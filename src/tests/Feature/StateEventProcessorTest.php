<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Messaging\PermanentMessageFailure;
use App\Models\ParkingSession;
use App\Models\Sensor;
use App\Models\Stall;
use App\Services\LiveStateStore;
use App\Services\SensorStallResolver;
use App\Services\StateEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\UsesTestRedis;
use Tests\TestCase;

class StateEventProcessorTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestRedis;

    private const START = 1_790_000_000;

    private StateEventProcessor $processor;

    private Stall $stall;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTestRedis();

        $this->stall = Stall::create(['code' => 'A-01']);
        Sensor::create(['sensor_uid' => 'sensor-1', 'stall_id' => $this->stall->id]);

        $this->processor = new StateEventProcessor(new SensorStallResolver(), new LiveStateStore());
    }

    public function test_occupied_with_no_active_session_opens_one(): void
    {
        $this->send('OCCUPIED', self::START);

        $session = ParkingSession::sole();

        $this->assertSame($this->stall->id, $session->stall_id);
        $this->assertSame(self::START, $session->started_at->timestamp);
        $this->assertNull($session->ended_at);
        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_occupied_with_an_active_session_is_ignored(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('OCCUPIED', self::START + 60);

        $this->assertSame(self::START, ParkingSession::sole()->started_at->timestamp);
    }

    public function test_vacant_closes_the_session_and_sets_the_duration(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('VACANT', self::START + 150);

        $session = ParkingSession::sole();

        $this->assertSame('completed', $session->status);
        $this->assertSame(self::START + 150, $session->ended_at->timestamp);
        $this->assertSame(2, $session->duration_minutes);
    }

    public function test_vacant_at_the_same_time_as_the_start_closes_the_session(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('VACANT', self::START);

        $session = ParkingSession::sole();

        $this->assertSame('completed', $session->status);
        $this->assertSame(0, $session->duration_minutes);
    }

    public function test_vacant_with_no_active_session_is_ignored(): void
    {
        $this->send('VACANT', self::START);

        $this->assertSame(0, ParkingSession::count());
    }

    public function test_vacant_older_than_the_session_start_is_ignored_for_sessions(): void
    {
        $this->send('OCCUPIED', self::START);

        Redis::del("stalls:{$this->stall->id}:state");
        $this->send('VACANT', self::START - 60);

        $this->assertSame('active', ParkingSession::sole()->fresh()->status);
    }

    public function test_late_occupied_does_not_open_a_session(): void
    {
        // VACANT arrives first, then the older OCCUPIED.
        $this->send('VACANT', self::START + 60);
        $this->send('OCCUPIED', self::START);

        $this->assertSame(0, ParkingSession::count());
    }

    public function test_late_occupied_does_not_merge_the_next_parking(): void
    {
        $this->send('VACANT', self::START + 60);
        $this->send('OCCUPIED', self::START);
        $this->send('OCCUPIED', self::START + 120);

        $this->assertSame(self::START + 120, ParkingSession::sole()->started_at->timestamp);
    }

    public function test_late_occupied_opens_a_session_if_the_redis_key_is_missing(): void
    {
        $this->send('VACANT', self::START + 60);
        Redis::del("stalls:{$this->stall->id}:state");

        $this->send('OCCUPIED', self::START);

        $this->assertSame(1, ParkingSession::count());
    }

    public function test_a_redelivered_occupied_of_a_closed_session_creates_no_second_session(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('VACANT', self::START + 60);

        // Redis is gone, so only the unique key can stop this one.
        Redis::del("stalls:{$this->stall->id}:state");
        $this->send('OCCUPIED', self::START);

        $this->assertSame(1, ParkingSession::count());
        $this->assertSame('completed', ParkingSession::sole()->status);
    }

    public function test_a_redelivered_vacant_changes_nothing(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('VACANT', self::START + 120);
        $this->send('VACANT', self::START + 120);

        $session = ParkingSession::sole();

        $this->assertSame(self::START + 120, $session->ended_at->timestamp);
        $this->assertSame(2, $session->duration_minutes);
    }

    public function test_a_new_parking_after_a_closed_session_opens_a_new_session(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('VACANT', self::START + 60);
        $this->send('OCCUPIED', self::START + 120);

        $this->assertSame(2, ParkingSession::count());
        $this->assertSame(1, ParkingSession::where('status', 'active')->count());
    }

    public function test_redis_keeps_the_newest_state(): void
    {
        $this->send('VACANT', self::START + 60);
        $this->send('OCCUPIED', self::START);

        $this->assertSame(
            ['state' => 'VACANT', 'last_updated_ts' => (string) (self::START + 60)],
            $this->liveState(),
        );
    }

    public function test_a_packet_with_the_same_timestamp_is_accepted_by_redis(): void
    {
        $this->send('OCCUPIED', self::START);
        $this->send('VACANT', self::START);

        $this->assertSame('VACANT', $this->liveState()['state']);
    }

    public function test_an_unknown_sensor_is_a_permanent_failure(): void
    {
        $this->expectException(PermanentMessageFailure::class);

        try {
            $this->processor->process($this->event('OCCUPIED', self::START, 'sensor-x'));
        } finally {
            $this->assertSame(0, ParkingSession::count());
            $this->assertSame(1, Stall::count());
        }
    }

    public function test_a_telemetry_event_is_a_permanent_failure(): void
    {
        $this->expectException(PermanentMessageFailure::class);

        $this->send('HEARTBEAT', self::START);
    }

    public function test_the_lock_is_released_after_each_event(): void
    {
        $this->send('OCCUPIED', self::START);

        $lock = Cache::lock("stalls:{$this->stall->id}:lock", 1);

        $this->assertTrue($lock->get());

        $lock->release();
    }

    private function send(string $eventType, int $timestamp): void
    {
        $this->processor->process($this->event($eventType, $timestamp));
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $eventType, int $timestamp, string $sensorUid = 'sensor-1'): array
    {
        return [
            'sensor_uid' => $sensorUid,
            'event_type' => $eventType,
            'timestamp' => $timestamp,
            'received_at' => $timestamp,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function liveState(): array
    {
        $state = Redis::hgetall("stalls:{$this->stall->id}:state");
        ksort($state);

        return ['state' => $state['state'], 'last_updated_ts' => $state['last_updated_ts']];
    }
}
