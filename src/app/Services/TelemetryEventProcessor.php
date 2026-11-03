<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SensorReading;
use Illuminate\Support\Carbon;

class TelemetryEventProcessor
{
    public function __construct(
        private readonly SensorStallResolver $resolver,
        private readonly LiveStateStore $liveState,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function process(array $event): void
    {
        $sensorUid = $event['sensor_uid'];
        $timestamp = (int) $event['timestamp'];

        $health = [
            'battery_level_pct' => $event['payload']['battery_level_pct'] ?? null,
            'temperature_c' => $event['payload']['temperature_c'] ?? null,
            'signal_rssi_dbm' => $event['payload']['signal_rssi_dbm'] ?? null,
        ];

        // The unique key ignores a duplicate packet.
        // The stall stays empty if the sensor is unknown.
        SensorReading::insertOrIgnore($health + [
            'sensor_uid' => $sensorUid,
            'stall_id' => $this->resolver->resolve($sensorUid),
            'event_type' => $event['event_type'],
            'recorded_at' => Carbon::createFromTimestampUTC($timestamp),
            'received_at' => Carbon::createFromTimestampUTC((int) $event['received_at']),
        ]);

        // Keep the last known value of anything this packet did not send.
        $this->liveState->writeSensorHealth(
            $sensorUid,
            array_filter($health, fn ($value) => $value !== null),
            $timestamp,
        );
    }
}
