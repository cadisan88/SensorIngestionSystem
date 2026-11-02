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

These assumptions explain the main design choices: fast acknowledgement in the API, idempotent workers, timestamp checks for out-of-order packets, and a separate path for sensor health data.


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
| 422 | The payload is not valid, or the timestamp is far in the future. Nothing is queued. |
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
| `GET /api/v1/health` | Liveness. The app is running. Checks no dependencies. Used by Docker healthchecks. |
| `GET /api/v1/health/ready` | Readiness. Checks MariaDB, Redis and RabbitMQ and reports each one. Returns `503` if any fails. |
