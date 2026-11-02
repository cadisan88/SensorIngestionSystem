<?php

declare(strict_types=1);

namespace App\Services;

use App\Messaging\PermanentMessageFailure;
use App\Models\ParkingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StateEventProcessor
{
    // Longer than one event takes. It frees the lock if a worker crashes.
    private const LOCK_TTL_SECONDS = 30;

    // If the lock is still taken after this, the message is requeued.
    private const LOCK_WAIT_SECONDS = 5;

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
        $state = $event['event_type'];
        $timestamp = (int) $event['timestamp'];

        if (! in_array($state, ['OCCUPIED', 'VACANT'], true)) {
            throw new PermanentMessageFailure("Not a state event: {$state}");
        }

        $stallId = $this->resolver->resolve($sensorUid);

        if ($stallId === null) {
            throw new PermanentMessageFailure("Unknown sensor: {$sensorUid}");
        }

        Cache::lock("stalls:{$stallId}:lock", self::LOCK_TTL_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            function () use ($stallId, $state, $timestamp): void {
                // The database is the source of truth, so it is written first.
                $this->applySessionRules($stallId, $state, $timestamp);
                $this->liveState->writeStallState($stallId, $state, $timestamp);
            },
        );
    }

    private function applySessionRules(int $stallId, string $state, int $timestamp): void
    {
        $at = Carbon::createFromTimestampUTC($timestamp);
        $lastUpdatedTs = $this->liveState->stallLastUpdatedTs($stallId);

        DB::transaction(function () use ($stallId, $state, $timestamp, $at, $lastUpdatedTs): void {
            $active = ParkingSession::where('stall_id', $stallId)
                ->where('status', 'active')
                ->first();

            if ($state === 'OCCUPIED') {
                $isLate = $lastUpdatedTs !== null && $timestamp < $lastUpdatedTs;

                $this->openSession($stallId, $at, $active, $isLate);
            } else {
                $this->closeSession($stallId, $at, $active);
            }
        });
    }

    private function openSession(int $stallId, Carbon $at, ?ParkingSession $active, bool $isLate): void
    {
        if ($isLate || $active !== null) {
            return;
        }

        // The unique key ignores a redelivered event of a session that is already closed.
        ParkingSession::insertOrIgnore(['stall_id' => $stallId, 'started_at' => $at]);
    }

    private function closeSession(int $stallId, Carbon $at, ?ParkingSession $active): void
    {
        if ($active === null) {
            Log::info('VACANT ignored: no active session', ['stall_id' => $stallId, 'at' => $at->timestamp]);

            return;
        }

        if ($at->lt($active->started_at)) {
            return;
        }

        $active->update([
            'ended_at' => $at,
            'duration_minutes' => (int) $active->started_at->diffInMinutes($at),
            'status' => 'completed',
        ]);
    }
}
