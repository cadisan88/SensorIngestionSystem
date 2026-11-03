<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ParkingSession;
use App\Models\Stall;
use App\Services\LiveStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesTestRedis;
use Tests\TestCase;

class StallStateTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestRedis;

    private const START = 1_790_000_000;

    private Stall $stall;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTestRedis();
        config(['ingress.gateway_token' => 'test-token']);

        $this->stall = Stall::create(['code' => 'A-01']);
    }

    public function test_a_missing_token_returns_401(): void
    {
        $this->getJson('/api/v1/stalls/A-01/state')->assertStatus(401);
    }

    public function test_an_unknown_stall_returns_404(): void
    {
        $this->read('Z-99')->assertStatus(404);
    }

    public function test_it_returns_the_live_state_from_redis(): void
    {
        (new LiveStateStore())->writeStallState($this->stall->id, 'OCCUPIED', self::START);

        $this->read('A-01')->assertOk()->assertExactJson([
            'stall' => 'A-01',
            'source' => 'redis',
            'state' => 'OCCUPIED',
            'last_updated_ts' => self::START,
        ]);
    }

    public function test_redis_wins_over_the_database(): void
    {
        $this->createSession(['status' => 'active']);
        (new LiveStateStore())->writeStallState($this->stall->id, 'VACANT', self::START + 60);

        $this->read('A-01')->assertJson(['state' => 'VACANT', 'source' => 'redis']);
    }

    public function test_with_no_redis_state_an_active_session_means_occupied(): void
    {
        $this->createSession(['status' => 'active']);

        $this->read('A-01')->assertOk()->assertExactJson([
            'stall' => 'A-01',
            'source' => 'database',
            'state' => 'OCCUPIED',
            'last_updated_ts' => self::START,
        ]);
    }

    public function test_with_no_redis_state_a_completed_session_means_vacant(): void
    {
        $this->createSession([
            'status' => 'completed',
            'ended_at' => date('Y-m-d H:i:s', self::START + 60),
            'duration_minutes' => 1,
        ]);

        $this->read('A-01')->assertOk()->assertExactJson([
            'stall' => 'A-01',
            'source' => 'database',
            'state' => 'VACANT',
            'last_updated_ts' => self::START + 60,
        ]);
    }

    public function test_the_latest_session_decides_the_state(): void
    {
        $this->createSession([
            'status' => 'completed',
            'ended_at' => date('Y-m-d H:i:s', self::START + 60),
            'duration_minutes' => 1,
        ]);
        $this->createSession(['status' => 'active', 'started_at' => date('Y-m-d H:i:s', self::START + 120)]);

        $this->read('A-01')->assertJson(['state' => 'OCCUPIED', 'last_updated_ts' => self::START + 120]);
    }

    public function test_a_stall_with_no_events_has_an_empty_state(): void
    {
        $this->read('A-01')->assertOk()->assertExactJson([
            'stall' => 'A-01',
            'source' => 'database',
            'state' => null,
            'last_updated_ts' => null,
        ]);
    }

    private function read(string $code): TestResponse
    {
        return $this->getJson("/api/v1/stalls/{$code}/state", ['X-Gateway-Token' => 'test-token']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSession(array $attributes): void
    {
        ParkingSession::create($attributes + [
            'stall_id' => $this->stall->id,
            'started_at' => date('Y-m-d H:i:s', self::START),
        ]);
    }
}
