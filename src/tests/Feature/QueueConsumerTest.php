<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Messaging\PermanentMessageFailure;
use App\Messaging\QueueConsumer;
use App\Messaging\RabbitMqConnectionFactory;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Must use reject, not nack: since RabbitMQ 4.3 only reject counts
 * toward the delivery limit.
 */
class QueueConsumerTest extends TestCase
{
    private const VALID_BODY = '{"sensor_uid":"sensor-1","event_type":"OCCUPIED","timestamp":1790000000,"received_at":1790000000}';

    private QueueConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new QueueConsumer(new RabbitMqConnectionFactory());
    }

    public function test_a_processed_message_is_acked(): void
    {
        $message = $this->message(self::VALID_BODY);
        $message->shouldReceive('ack')->once();
        $message->shouldNotReceive('reject', 'nack');

        $received = null;
        $this->consumer->handle('test', $message, function (array $event) use (&$received): void {
            $received = $event;
        });

        $this->assertSame('sensor-1', $received['sensor_uid']);
    }

    public function test_a_permanent_failure_goes_to_the_dead_letter_queue(): void
    {
        $message = $this->message(self::VALID_BODY);
        $message->shouldReceive('reject')->once()->with(false);
        $message->shouldNotReceive('ack', 'nack');

        $this->consumer->handle('test', $message, function (): void {
            throw new PermanentMessageFailure('Unknown sensor');
        });
    }

    public function test_any_other_failure_is_requeued_with_reject(): void
    {
        $message = $this->message(self::VALID_BODY);
        $message->shouldReceive('reject')->once()->with(true);
        $message->shouldNotReceive('ack', 'nack');

        $this->consumer->handle('test', $message, function (): void {
            throw new RuntimeException('Database is down');
        });
    }

    #[DataProvider('corruptBodies')]
    public function test_a_corrupt_message_never_reaches_the_handler(string $body): void
    {
        $message = $this->message($body);
        $message->shouldReceive('reject')->once()->with(false);
        $message->shouldNotReceive('ack', 'nack');

        $this->consumer->handle('test', $message, function (): void {
            $this->fail('The handler must not run.');
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function corruptBodies(): array
    {
        return [
            'not JSON' => ['not json'],
            'not an object' => ['"text"'],
            'no sensor' => ['{"event_type":"OCCUPIED","timestamp":1,"received_at":1}'],
            'no event type' => ['{"sensor_uid":"s","timestamp":1,"received_at":1}'],
            'no timestamp' => ['{"sensor_uid":"s","event_type":"OCCUPIED","received_at":1}'],
            'no received_at' => ['{"sensor_uid":"s","event_type":"OCCUPIED","timestamp":1}'],
            'bad timestamp' => ['{"sensor_uid":"s","event_type":"OCCUPIED","timestamp":"soon","received_at":1}'],
        ];
    }

    private function message(string $body): AMQPMessage&MockInterface
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn($body);

        return $message;
    }
}
