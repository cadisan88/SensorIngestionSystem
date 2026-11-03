<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sensor;
use App\Models\Stall;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesTestRedis;
use Tests\TestCase;

class StallListTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTestRedis();
        config(['ingress.gateway_token' => 'test-token']);
    }

    public function test_a_missing_token_returns_401(): void
    {
        $this->getJson('/api/v1/stalls')->assertStatus(401);
    }

    public function test_a_wrong_token_returns_401(): void
    {
        $this->getJson('/api/v1/stalls', ['X-Gateway-Token' => 'wrong'])->assertStatus(401);
    }

    public function test_no_stalls_returns_an_empty_list(): void
    {
        $this->list()->assertOk()->assertExactJson(['stalls' => []]);
    }

    public function test_stalls_are_sorted_by_code_with_their_sensors(): void
    {
        $second = Stall::create(['code' => 'B-01']);
        $first = Stall::create(['code' => 'A-01']);
        Sensor::create(['sensor_uid' => 'sensor-b', 'stall_id' => $second->id]);
        Sensor::create(['sensor_uid' => 'sensor-a', 'stall_id' => $first->id]);

        $this->list()->assertOk()->assertExactJson([
            'stalls' => [
                ['code' => 'A-01', 'sensors' => ['sensor-a']],
                ['code' => 'B-01', 'sensors' => ['sensor-b']],
            ],
        ]);
    }

    public function test_a_stall_with_no_sensor_has_an_empty_list(): void
    {
        Stall::create(['code' => 'A-01']);

        $this->list()->assertExactJson([
            'stalls' => [
                ['code' => 'A-01', 'sensors' => []],
            ],
        ]);
    }

    public function test_it_lists_the_seeded_demo_stalls(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->list()
            ->assertOk()
            ->assertJsonCount(5, 'stalls')
            ->assertJsonPath('stalls.0', ['code' => 'A-01', 'sensors' => ['sensor-99f4a1']]);
    }

    private function list(): TestResponse
    {
        return $this->getJson('/api/v1/stalls', ['X-Gateway-Token' => 'test-token']);
    }
}
