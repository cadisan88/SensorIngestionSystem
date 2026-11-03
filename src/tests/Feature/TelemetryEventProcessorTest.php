<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sensor;
use App\Models\SensorReading;
use App\Models\Stall;
use App\Services\LiveStateStore;
use App\Services\SensorStallResolver;
use App\Services\TelemetryEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\UsesTestRedis;
use Tests\TestCase;

class TelemetryEventProcessorTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestRedis;

    private const START = 1_790_000_000;

    private TelemetryEventProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTestRedis();
        $this->processor = new TelemetryEventProcessor(new SensorStallResolver(), new LiveStateStore());
    }

    public function test_a_packet_is_stored_as_a_reading(): void
    {
        $stall = Stall::create(['code' => 'A-01']);
        Sensor::create(['sensor_uid' => 'sensor-1', 'stall_id' => $stall->id]);

        $this->processor->process($this->event('sensor-1', self::START));

        $reading = SensorReading::sole();

        $this->assertSame($stall->id, $reading->stall_id);
        $this->assertSame('HEARTBEAT', $reading->event_type);
        $this->assertSame('98.50', $reading->battery_level_pct);
        $this->assertSame(-85, $reading->signal_rssi_dbm);
        $this->assertSame(self::START, $reading->recorded_at->timestamp);
        $this->assertSame(self::START + 5, $reading->received_at->timestamp);
    }

    public function test_an_unknown_sensor_is_stored_with_an_empty_stall(): void
    {
        $this->processor->process($this->event('sensor-x', self::START));

        $this->assertNull(SensorReading::sole()->stall_id);
    }

    public function test_a_duplicate_packet_is_stored_once(): void
    {
        $this->processor->process($this->event('sensor-1', self::START));
        $this->processor->process($this->event('sensor-1', self::START));

        $this->assertSame(1, SensorReading::count());
    }

    public function test_a_different_event_type_at_the_same_time_is_a_new_reading(): void
    {
        $this->processor->process($this->event('sensor-1', self::START));
        $this->processor->process(['event_type' => 'ERROR'] + $this->event('sensor-1', self::START));

        $this->assertSame(2, SensorReading::count());
    }

    public function test_a_late_packet_is_stored_but_does_not_change_sensor_health(): void
    {
        $this->processor->process($this->event('sensor-1', self::START, 50));
        $this->processor->process($this->event('sensor-1', self::START - 60, 90));

        $this->assertSame(2, SensorReading::count());

        $health = Redis::hgetall('sensors:sensor-1:telemetry');

        $this->assertEquals(50, $health['battery_level_pct']);
        $this->assertEquals(self::START, $health['last_seen_at']);
    }

    public function test_a_packet_with_no_payload_keeps_the_last_known_health(): void
    {
        $this->processor->process($this->event('sensor-1', self::START, 50));

        $event = $this->event('sensor-1', self::START + 60);
        unset($event['payload']);
        $this->processor->process($event);

        $health = Redis::hgetall('sensors:sensor-1:telemetry');

        $this->assertEquals(50, $health['battery_level_pct']);
        $this->assertEquals(self::START + 60, $health['last_seen_at']);
        $this->assertNull(SensorReading::latest('recorded_at')->first()->battery_level_pct);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $sensorUid, int $timestamp, float $battery = 98.5): array
    {
        return [
            'sensor_uid' => $sensorUid,
            'event_type' => 'HEARTBEAT',
            'payload' => [
                'battery_level_pct' => $battery,
                'temperature_c' => 14.2,
                'signal_rssi_dbm' => -85,
            ],
            'timestamp' => $timestamp,
            'received_at' => $timestamp + 5,
        ];
    }
}
