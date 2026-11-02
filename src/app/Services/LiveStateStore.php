<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class LiveStateStore
{
    /**
     * Writes a hash only if the packet is not older than the stored time.
     * Redis runs the script as one atomic step.
     *
     * KEYS[1]: the hash. ARGV[1]: the time field. ARGV[2]: the packet time.
     * Other ARGV values: field and value pairs.
     */
    private const WRITE_IF_NOT_OLDER = <<<'LUA'
        local current = redis.call('HGET', KEYS[1], ARGV[1])
        if current and tonumber(ARGV[2]) < tonumber(current) then
            return 0
        end
        redis.call('HSET', KEYS[1], ARGV[1], ARGV[2], unpack(ARGV, 3))
        return 1
        LUA;

    /**
     * Returns the time of the newest state event, or null if there is none yet.
     */
    public function stallLastUpdatedTs(int $stallId): ?int
    {
        $value = Redis::hget("stalls:{$stallId}:state", 'last_updated_ts');

        return is_numeric($value) ? (int) $value : null;
    }

    public function writeStallState(int $stallId, string $state, int $timestamp): void
    {
        $this->writeIfNotOlder("stalls:{$stallId}:state", 'last_updated_ts', $timestamp, ['state' => $state]);
    }

    /**
     * @param  array<string, mixed>  $health
     */
    public function writeSensorHealth(string $sensorUid, array $health, int $timestamp): void
    {
        $this->writeIfNotOlder("sensors:{$sensorUid}:telemetry", 'last_seen_at', $timestamp, $health);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function writeIfNotOlder(string $key, string $timeField, int $timestamp, array $fields): void
    {
        $pairs = [];

        foreach ($fields as $field => $value) {
            $pairs[] = $field;
            $pairs[] = $value;
        }

        Redis::eval(self::WRITE_IF_NOT_OLDER, 1, $key, $timeField, $timestamp, ...$pairs);
    }
}
