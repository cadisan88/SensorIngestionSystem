# Sensor Ingestion System

A small backend that ingests events from battery-powered parking sensors, keeps the live state of every stall in Redis, and stores parking history in MariaDB.

It is a proof of concept. The goal is to show a clean, event-driven design with PHP, Laravel, RabbitMQ and Redis, not to build a full product.

## The problem

Parking sensors send a packet when a car arrives or leaves, plus a heartbeat a few times per day. LoRaWAN devices run on batteries and send very little data, so events are rare per sensor but can arrive in bursts across thousands of stalls.

The backend must:

- Accept events quickly and never block the sender.
- Show the current state of any stall with very low latency.
- Keep a reliable history (parking sessions and sensor health).
- Survive duplicate, late, unknown, or broken messages.

## Sensor assumptions

The design is based on these assumptions about the sensors and the network. They are assumptions for this proof of concept. They are not measurements from a real device.

| Topic | Assumption |
|-------|------------|
| Network | Sensors use LoRaWAN, a low power radio network with very small packets. Sensors do not call the API directly. A gateway or network server decodes the radio payload and forwards it as JSON over HTTPS. |
| Detection | Sensors detect cars by themselves (event driven). The server never polls them. |
| State changes | When a car arrives or leaves, the sensor wakes up and sends an `OCCUPIED` or `VACANT` event within seconds. |
| Heartbeat | When nothing changes, the sensor sleeps. It sends a `HEARTBEAT` about every 6 hours (2 to 4 times per day) to show that it is alive and to report its battery. A heartbeat never changes the stall state. |
| Battery | Sensors run on batteries designed to last several years, so they send as little as possible. Battery level, temperature and signal strength travel inside the payload. |
| Faults | If a sensor finds a problem (for example a badly calibrated detector), it sends an `ERROR` event. |
| Identity | A sensor only knows its own ID. The server maps the sensor to a stall. |
| Timestamp | Each packet has a Unix timestamp in seconds, set upstream when the event happened. The server also stores the time it received the packet. |
| Delivery | Delivery is not perfect. Packets can arrive duplicated, late, out of order, or never. The sensor does not wait for a reply from this system. Retries are the job of the gateway. |
| Traffic | Each sensor sends little data, but many sensors can report at the same time. |

These assumptions explain the main design choices: fast acknowledgement in the API, idempotent workers, timestamp checks for out of order packets, and a separate path for sensor health data.

## How it works

```
LoRaWAN network server
        |  HTTPS POST (decoded JSON, gateway token)
        v
  Nginx -> PHP-FPM -> Ingress API (Laravel)
        |  validate, publish (persistent), wait for broker confirm
        v
  RabbitMQ topic exchange: sensor.events
        |
        |-- sensor.state.*  --> real_time_state_processing --> State worker
        |                                                        |-- Redis (live state)
        |                                                        '-- MariaDB (sessions)
        |
        '-- sensor.#        --> telemetry_analytics        --> Telemetry worker
                                                                 '-- MariaDB (readings)

  Messages that fail for good --> dead_letter_queue
```

### 1. Ingress API

`POST /api/v1/telemetry/ingress`

The controller checks the gateway token, validates the payload, publishes it to RabbitMQ, and waits for RabbitMQ to confirm it stored the message. It does no database or disk work.

The sensor only sends its own ID. The server decides which stall the sensor belongs to (see "Sensor to stall mapping").

`gateway_id` is required, but it is not stored or compared against anything. This keeps the proof of concept simple.

```json
{
  "sensor_uid": "sensor-99f4a1",
  "gateway_id": "gw-01",
  "event_type": "OCCUPIED",
  "payload": {
    "battery_level_pct": 98.5,
    "temperature_c": 14.2,
    "signal_rssi_dbm": -85
  },
  "timestamp": 1793397360
}
```

Authentication: the gateway sends a shared secret in the `X-Gateway-Token` header. The secret is set in `.env`.

| Status | Meaning |
|--------|---------|
| 202 | RabbitMQ confirmed the message. Body has a `tracking_id`. |
| 401 | Missing or wrong gateway token. |
| 422 | The payload is not valid, or the timestamp is too far in the future (5 minutes by default, set by `INGRESS_MAX_FUTURE_SECONDS` in `.env`). Nothing is queued. |
| 503 | The message could not be confirmed by RabbitMQ. The gateway should retry. |

Event types:

| Type | Meaning |
|------|---------|
| `OCCUPIED` | A car parked. Starts a session. |
| `VACANT` | A car left. Ends the session and sets the duration. |
| `HEARTBEAT` | Health check. Updates battery, temperature and signal only. |
| `ERROR` | The sensor reported a fault. Stored as a reading. Stall state does not change. |

### 2. Health endpoints

These are public, return only up or down, and show no version details.

| Endpoint | Purpose |
|----------|---------|
| `GET /api/v1/health` | Liveness. The app is running. Checks no dependency. Used by Docker healthchecks. |
| `GET /api/v1/health/ready` | Readiness. Checks MariaDB, Redis and RabbitMQ and reports each one. Returns `503` if any fails. |

### 3. RabbitMQ topology

- Exchange: `sensor.events` (topic, durable)
- Routing keys: `sensor.state.occupied`, `sensor.state.vacant`, `sensor.telemetry.heartbeat`, `sensor.telemetry.error`
- Dead letter exchange: `sensor.events.dlx` (fanout, durable)

| Queue | Binding | Purpose |
|-------|---------|---------|
| `real_time_state_processing` | `sensor.state.*` | Fast path: live stall state and sessions |
| `telemetry_analytics` | `sensor.#` | Stores every frame as a sensor reading |
| `dead_letter_queue` | from `sensor.events.dlx` | Keeps messages that cannot be processed. It has no delivery limit, so they are never dropped. |

Decisions:

- **Quorum queues with a delivery limit.** A message that fails 5 times is moved to `dead_letter_queue` by RabbitMQ itself. Workers do not need their own retry counter.
- **Persistent messages and publisher confirms.** Messages are published as persistent, and the API returns `202` only after RabbitMQ confirms it.
- **One setup command.** An idempotent Artisan command (`rabbitmq:setup`) declares the exchanges, queues, bindings and dead letter settings. Docker Compose runs it before the API and the workers start. The API never declares queues.
- The two business queues are separate, so a slow history write never delays the live state.

### 4. Sensor to stall mapping

The server owns the mapping. Sensors do not know their stall.

- The `sensors` table links `sensor_uid` to a stall.
- Workers resolve the stall in this order: Redis, then MariaDB, then write the result to Redis.
- Found mappings are cached with no expiry (`sensors:{sensor_uid}:stall`).
- Whoever changes a mapping (a seeder or a command) must also clear the Redis keys of that sensor: the mapping and the `unknown` marker.

**Unknown sensors**

- The state worker treats it as a permanent error. It rejects the message into `dead_letter_queue` and logs a clear line. No stall is ever created automatically.
- The telemetry worker still stores the reading with an empty stall, because battery and signal data are still valid.
- "Unknown" is remembered in Redis for 60 seconds, so a flood from one unregistered device does not hit the database.
- After the sensor is registered, the messages in `dead_letter_queue` can be published again.

### 5. Live state in Redis

| Key | Type | Content |
|-----|------|---------|
| `stalls:{stall_id}:state` | hash | `state` (OCCUPIED or VACANT), `last_updated_ts` |
| `sensors:{sensor_uid}:telemetry` | hash | battery, temperature, RSSI, `last_seen_at` |
| `sensors:{sensor_uid}:stall` | string | stall id, no expiry |
| `sensors:{sensor_uid}:unknown` | string | marker for unknown sensors, expires after 60 seconds |

Radio packets can arrive out of order. A small Lua script compares the packet timestamp with `last_updated_ts` and writes only if the packet is not older. The check and the write happen in one atomic step. A packet with the same timestamp is accepted, so processing a message twice gives the same result.

Stall state has no expiry. A reader can judge freshness with `last_updated_ts` and `last_seen_at`.

### 6. Workers

Workers are Laravel Artisan commands that use `php-amqplib` directly. They are stateless, so you can run as many as you need.

**State worker**
1. Resolve the stall from the sensor ID. If unknown, reject to the dead letter queue.
2. Take a Redis lock for the stall, so one event per stall is handled at a time. If the lock is taken, wait a few seconds. If it is still taken, requeue the message.
3. In one database transaction, apply the session rules (below).
4. Update the live state in Redis (Lua script).
5. Release the lock and `ack` the message.

MariaDB is the source of truth and Redis is a fast copy, so the database is written first. If the worker crashes after step 3, RabbitMQ delivers the message again. The database step ignores the duplicate and the Redis step is safe to repeat.

**Telemetry worker**
1. Resolve the stall (empty if the sensor is unknown).
2. Store the frame in `sensor_readings`. Duplicates are ignored by a unique key.
3. Update the sensor health hash in Redis.
4. `ack` the message.

**Session rules**

| Situation | Result |
|-----------|--------|
| `OCCUPIED`, older than `last_updated_ts` in Redis | Ignore for sessions. `sensor_readings` still keeps it. |
| `OCCUPIED`, no active session | Open a session |
| `OCCUPIED`, active session exists | Ignore (duplicate) |
| `VACANT`, active session, event is not older than the start | Close the session and set the duration |
| `VACANT`, no active session | Ignore and log |
| `VACANT`, older than the session start | Ignore for sessions |

The first rule is checked first. If the Redis key is missing, the other rules apply.

Redis still keeps the newest state by timestamp in every case.

In the database, the state of a stall comes from its latest session (by `started_at`): `active` means occupied.

### 7. Data model (MariaDB)

| Table | Main columns |
|-------|--------------|
| `stalls` | `id`, `code`, `created_at` |
| `sensors` | `id`, `sensor_uid` (unique), `stall_id`, `created_at` |
| `parking_sessions` | `id`, `stall_id`, `started_at`, `ended_at`, `duration_minutes`, `status` (`active` or `completed`) |
| `sensor_readings` | `id`, `sensor_uid`, `stall_id` (can be empty), `event_type`, `battery_level_pct`, `temperature_c`, `signal_rssi_dbm`, `recorded_at`, `received_at` |

`recorded_at` is the time in the packet. `received_at` is when the server got it. The API sets `received_at` and puts it in the message. All ordering rules use `recorded_at`.

Schema changes are versioned with Laravel migrations. A seeder creates demo stalls and sensors.

## Reliability decisions

| Risk | How it is handled |
|------|-------------------|
| Worker crashes mid-message | Manual acknowledgements. A message is acked only after the database and Redis both succeed, so RabbitMQ delivers it again (at-least-once). |
| RabbitMQ restarts | Quorum queues, persistent messages, and publisher confirms. `202` means the message is stored. |
| Duplicate messages | Idempotent writes. A duplicate `OCCUPIED` does not create a second session. Duplicate readings are ignored by a unique key. |
| Late or out-of-order packets | The Redis Lua script ignores older timestamps for live state. History still records the event. |
| Two events for one stall at the same time | A Redis lock per stall. |
| Message keeps failing | After 5 deliveries, RabbitMQ moves it to `dead_letter_queue`. |
| Corrupt message or unknown sensor | Rejected without re-queue and moved to `dead_letter_queue`. |
| Slow database | The API does not use it. Messages wait in the queue. |
| Open endpoint | The gateway token is required. |

## Design notes

- **Publishing cost.** PHP-FPM starts each request from scratch, so each ingress request opens its own RabbitMQ connection. This is a known trade-off, accepted to keep the design simple.
- **Ordering.** RabbitMQ does not guarantee order across several consumers. This project relies on packet timestamps and the per-stall lock.

## Tech stack

- PHP 8.4.27 (FPM), Laravel 13
- Nginx
- RabbitMQ 4 (`php-amqplib`)
- Redis 7
- MariaDB 11
- Docker Compose
- PHPUnit

The Laravel app lives in `src/`. The Docker files are in the repo root. The root `.env` is the only config file, and Compose passes its values to the containers.

## Running it locally

Requirements: Docker and Docker Compose.

```bash
cp .env.example .env

# Install the PHP packages. They are not stored in git.
docker compose run --rm --no-deps app composer install

# Print a new key. Paste it into APP_KEY in .env.
docker compose run --rm --no-deps app php artisan key:generate --show

docker compose up -d --build
docker compose exec app php artisan migrate --seed
```

`docker compose up` starts everything, including both workers. The one-shot `rabbitmq-setup` container creates the RabbitMQ topology before the API and the workers start. This cannot happen in the Dockerfile, because no broker exists at build time. It is normal to see `rabbitmq-setup` as exited.

Check that it is running:

```bash
curl http://localhost:8081/api/v1/health
curl http://localhost:8081/api/v1/health/ready
```

Send a test event (use the token from your `.env`):

```bash
curl -X POST http://localhost:8081/api/v1/telemetry/ingress \
  -H "Content-Type: application/json" \
  -H "X-Gateway-Token: <GATEWAY_TOKEN>" \
  -d '{
    "sensor_uid": "sensor-99f4a1",
    "gateway_id": "gw-01",
    "event_type": "OCCUPIED",
    "payload": {
      "battery_level_pct": 98.5,
      "temperature_c": 14.2,
      "signal_rssi_dbm": -85
    },
    "timestamp": 1793397360
  }'
```

Or use the gateway emulator, an Artisan command that sends real HTTP requests to the endpoint:

```bash
# One OCCUPIED packet from the demo sensor
docker compose exec app php artisan gateway:emulate OCCUPIED

# A heartbeat from another sensor, with a low battery
docker compose exec app php artisan gateway:emulate HEARTBEAT sensor-99f4a2 --battery=12

# The same packet 3 times (duplicates)
docker compose exec app php artisan gateway:emulate OCCUPIED --count=3

# A packet that is 5 minutes late
docker compose exec app php artisan gateway:emulate VACANT --offset=-300

# An unknown sensor, and a wrong token
docker compose exec app php artisan gateway:emulate OCCUPIED sensor-unknown
docker compose exec app php artisan gateway:emulate OCCUPIED --token=wrong
```

See all options with `docker compose exec app php artisan gateway:emulate --help`.

Scale the workers:

```bash
docker compose up -d --scale state-worker=3
```

### Admin access

The API is the only service open to the network. The services below are published on `127.0.0.1` only, so you can inspect them from your own machine and nobody else can. Host ports and passwords are set in `.env`.

| Service | Default address |
|---------|-----------------|
| MariaDB | `127.0.0.1:3307` |
| Redis | `127.0.0.1:6380` |
| RabbitMQ management page | `http://localhost:15672` |
| phpMyAdmin | `http://localhost:8080` |

Workers are long-running PHP processes. After changing worker code, run `docker compose restart state-worker telemetry-worker`.

## Tests

```bash
docker compose exec app vendor/bin/phpunit
```

Tests cover the gateway token, request validation, routing keys, sensor to stall mapping (including unknown sensors), the session rules, out-of-order handling, duplicates, and how workers ack or reject messages.

Tests use SQLite in memory and Redis database 15, so they do not touch your real data. Redis must be running. The publisher is mocked, so these tests do not cover the real connection to RabbitMQ.

## Future work

- **Consistent-hash exchange.** Route all events of one stall to the same consumer, so each stall is processed in order without relying only on timestamps and locks.
- **Cheaper publishing.** If traffic grows, keep one RabbitMQ connection open and reuse it, either with Laravel Octane or with a small dedicated publisher service.

## Scope

Included: ingestion, live state, session history, sensor health, and failure handling.

Not included: user accounts, a user interface, real LoRaWAN decoding (the API receives already decoded JSON), production monitoring, and TLS. Nginx serves plain HTTP here. In production, TLS would end in front of it, because the gateway token must not travel unencrypted.

## Project status

Implemented: the ingress API, the RabbitMQ topology, both workers, the live state in Redis, the session rules, the demo seeder, the gateway emulator, and the PHPUnit tests.

Checked by hand against the real services, not by the automated tests: publisher confirms, a `503` when RabbitMQ is down, messages surviving a broker restart, the delivery limit, and a clean worker shutdown.

No speed or load measurements were made.
