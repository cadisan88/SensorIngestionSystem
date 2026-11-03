<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sensor;
use App\Models\Stall;
use App\Services\SensorStallResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\UsesTestRedis;
use Tests\TestCase;

class SensorStallResolverTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestRedis;

    private SensorStallResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTestRedis();
        $this->resolver = new SensorStallResolver();
    }

    public function test_a_known_sensor_resolves_to_its_stall(): void
    {
        $stall = $this->registerSensor('sensor-1');

        $this->assertSame($stall->id, $this->resolver->resolve('sensor-1'));
    }

    public function test_a_found_mapping_is_cached_with_no_expiry(): void
    {
        $stall = $this->registerSensor('sensor-1');

        $this->resolver->resolve('sensor-1');

        $this->assertEquals($stall->id, Redis::get('sensors:sensor-1:stall'));
        $this->assertSame(-1, Redis::ttl('sensors:sensor-1:stall'));
    }

    public function test_a_cached_mapping_does_not_query_the_database(): void
    {
        $stall = $this->registerSensor('sensor-1');
        $this->resolver->resolve('sensor-1');

        $this->assertSame($stall->id, $this->countingQueries($count, 'sensor-1'));
        $this->assertSame(0, $count);
    }

    public function test_an_unknown_sensor_returns_null_and_creates_no_stall(): void
    {
        $this->assertNull($this->resolver->resolve('sensor-x'));

        $this->assertSame(0, Stall::count());
        $this->assertSame(0, Sensor::count());
    }

    public function test_unknown_is_remembered_for_60_seconds(): void
    {
        $this->resolver->resolve('sensor-x');

        $ttl = Redis::ttl('sensors:sensor-x:unknown');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
        $this->assertSame(0, Redis::exists('sensors:sensor-x:stall'));
    }

    public function test_a_remembered_unknown_sensor_does_not_query_the_database(): void
    {
        $this->resolver->resolve('sensor-x');

        $this->assertNull($this->countingQueries($count, 'sensor-x'));
        $this->assertSame(0, $count);
    }

    public function test_forget_makes_a_new_registration_work_at_once(): void
    {
        $this->resolver->resolve('sensor-x');
        $stall = $this->registerSensor('sensor-x');

        $this->assertNull($this->resolver->resolve('sensor-x'));

        $this->resolver->forget('sensor-x');

        $this->assertSame($stall->id, $this->resolver->resolve('sensor-x'));
    }

    public function test_forget_makes_a_changed_mapping_work_at_once(): void
    {
        $this->registerSensor('sensor-1');
        $this->resolver->resolve('sensor-1');

        $newStall = Stall::create(['code' => 'B-01']);
        Sensor::where('sensor_uid', 'sensor-1')->update(['stall_id' => $newStall->id]);
        $this->resolver->forget('sensor-1');

        $this->assertSame($newStall->id, $this->resolver->resolve('sensor-1'));
    }

    private function registerSensor(string $sensorUid): Stall
    {
        $stall = Stall::create(['code' => 'A-'.$sensorUid]);
        Sensor::create(['sensor_uid' => $sensorUid, 'stall_id' => $stall->id]);

        return $stall;
    }

    private function countingQueries(?int &$count, string $sensorUid): ?int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->resolver->resolve($sensorUid);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $result;
    }
}
