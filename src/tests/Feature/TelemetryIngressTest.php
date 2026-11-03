<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Messaging\EventPublisher;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The publisher is mocked here, so these tests need no broker.
 */
class TelemetryIngressTest extends TestCase
{
    private const URL = '/api/v1/telemetry/ingress';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingress.gateway_token' => 'test-token', 'ingress.max_future_seconds' => 300]);
        $this->freezeTime();
    }

    public function test_a_missing_token_returns_401(): void
    {
        $this->expectNoPublish();

        $this->postJson(self::URL, $this->packet())->assertStatus(401);
    }

    public function test_a_wrong_token_returns_401(): void
    {
        $this->expectNoPublish();

        $this->send($this->packet(), 'wrong-token')->assertStatus(401);
    }

    public function test_an_empty_secret_never_matches_an_empty_token(): void
    {
        config(['ingress.gateway_token' => '']);
        $this->expectNoPublish();

        $this->send($this->packet(), '')->assertStatus(401);
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('invalidPackets')]
    public function test_an_invalid_packet_returns_422_and_is_not_published(array $change, string $invalidField): void
    {
        $this->expectNoPublish();

        $this->send(array_replace_recursive($this->packet(), $change))
            ->assertStatus(422)
            ->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPackets(): array
    {
        return [
            'empty sensor' => [['sensor_uid' => ''], 'sensor_uid'],
            'sensor too long' => [['sensor_uid' => str_repeat('a', 65)], 'sensor_uid'],
            'empty gateway' => [['gateway_id' => ''], 'gateway_id'],
            'unknown event type' => [['event_type' => 'PARKED'], 'event_type'],
            'battery over 100' => [['payload' => ['battery_level_pct' => 101]], 'payload.battery_level_pct'],
            'temperature out of range' => [['payload' => ['temperature_c' => 1000]], 'payload.temperature_c'],
            'positive signal' => [['payload' => ['signal_rssi_dbm' => 1]], 'payload.signal_rssi_dbm'],
            'timestamp not a number' => [['timestamp' => 'soon'], 'timestamp'],
        ];
    }

    public function test_a_timestamp_past_the_future_limit_returns_422(): void
    {
        $this->expectNoPublish();

        $this->send(['timestamp' => now()->timestamp + 301] + $this->packet())
            ->assertStatus(422)
            ->assertJsonValidationErrors('timestamp');
    }

    public function test_a_timestamp_at_the_future_limit_is_accepted(): void
    {
        $this->expectPublish('sensor.state.occupied');

        $this->send(['timestamp' => now()->timestamp + 300] + $this->packet())->assertStatus(202);
    }

    public function test_the_future_limit_comes_from_config(): void
    {
        config(['ingress.max_future_seconds' => 10]);
        $this->expectNoPublish();

        $this->send(['timestamp' => now()->timestamp + 11] + $this->packet())->assertStatus(422);
    }

    public function test_a_very_old_timestamp_is_accepted(): void
    {
        $this->expectPublish('sensor.state.occupied');

        $this->send(['timestamp' => now()->timestamp - 86400] + $this->packet())->assertStatus(202);
    }

    public function test_the_payload_is_optional(): void
    {
        $packet = $this->packet();
        unset($packet['payload']);

        $this->expectPublish('sensor.state.occupied');

        $this->send($packet)->assertStatus(202);
    }

    #[DataProvider('routingKeys')]
    public function test_each_event_type_uses_its_routing_key(string $eventType, string $routingKey): void
    {
        $this->expectPublish($routingKey);

        $this->send(['event_type' => $eventType] + $this->packet())->assertStatus(202);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routingKeys(): array
    {
        return [
            'occupied' => ['OCCUPIED', 'sensor.state.occupied'],
            'vacant' => ['VACANT', 'sensor.state.vacant'],
            'heartbeat' => ['HEARTBEAT', 'sensor.telemetry.heartbeat'],
            'error' => ['ERROR', 'sensor.telemetry.error'],
        ];
    }

    public function test_the_message_has_the_packet_and_the_server_fields(): void
    {
        $published = null;

        $this->mock(EventPublisher::class)
            ->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function (string $routingKey, array $body) use (&$published): void {
                $published = $body;
            });

        $response = $this->send($this->packet())->assertStatus(202);

        $this->assertSame($response->json('tracking_id'), $published['tracking_id']);
        $this->assertSame(now()->timestamp, $published['received_at']);
        $this->assertSame('sensor-1', $published['sensor_uid']);
        $this->assertSame(98.5, $published['payload']['battery_level_pct']);
    }

    public function test_a_failed_publish_returns_503(): void
    {
        $this->mock(EventPublisher::class)
            ->shouldReceive('publish')
            ->once()
            ->andThrow(new RuntimeException('secret broker detail'));

        $this->send($this->packet())
            ->assertStatus(503)
            ->assertDontSee('secret broker detail');
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function send(array $packet, string $token = 'test-token'): TestResponse
    {
        return $this->postJson(self::URL, $packet, ['X-Gateway-Token' => $token]);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return [
            'sensor_uid' => 'sensor-1',
            'gateway_id' => 'gw-01',
            'event_type' => 'OCCUPIED',
            'payload' => [
                'battery_level_pct' => 98.5,
                'temperature_c' => 14.2,
                'signal_rssi_dbm' => -85,
            ],
            'timestamp' => now()->timestamp,
        ];
    }

    private function expectNoPublish(): void
    {
        $this->mock(EventPublisher::class)->shouldNotReceive('publish');
    }

    private function expectPublish(string $routingKey): void
    {
        $this->mock(EventPublisher::class)
            ->shouldReceive('publish')
            ->once()
            ->withArgs(fn (string $key, array $body) => $key === $routingKey);
    }
}
