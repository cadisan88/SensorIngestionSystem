<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sensor;
use Illuminate\Support\Facades\Redis;

class SensorStallResolver
{
    // Short, so a newly registered sensor is not treated as unknown for long.
    private const UNKNOWN_TTL_SECONDS = 60;

    /**
     * Returns the stall id of a sensor, or null if the sensor is unknown.
     * Order: Redis, then the database, then save the result in Redis.
     */
    public function resolve(string $sensorUid): ?int
    {
        $stallId = Redis::get($this->stallKey($sensorUid));

        if ($stallId !== null) {
            return (int) $stallId;
        }

        // Known to be unknown: do not ask the database again.
        if (Redis::exists($this->unknownKey($sensorUid))) {
            return null;
        }

        $stallId = Sensor::where('sensor_uid', $sensorUid)->value('stall_id');

        if ($stallId === null) {
            Redis::setex($this->unknownKey($sensorUid), self::UNKNOWN_TTL_SECONDS, '1');

            return null;
        }

        // No expiry. Whoever changes the mapping must call forget().
        Redis::set($this->stallKey($sensorUid), $stallId);

        return (int) $stallId;
    }

    /**
     * Call this after a mapping changes. The next resolve() reads the database.
     */
    public function forget(string $sensorUid): void
    {
        Redis::del($this->stallKey($sensorUid), $this->unknownKey($sensorUid));
    }

    private function stallKey(string $sensorUid): string
    {
        return "sensors:{$sensorUid}:stall";
    }

    private function unknownKey(string $sensorUid): string
    {
        return "sensors:{$sensorUid}:unknown";
    }
}
