<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\Log;
use JsonException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class QueueConsumer
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
    ) {
    }

    /**
     * Runs until the process gets SIGTERM or SIGINT.
     * If the connection fails, the error stops the process and Docker restarts it.
     *
     * @param  callable(array<string, mixed>): void  $handler
     */
    public function consume(string $queue, callable $handler): void
    {
        $running = true;
        $stop = function () use (&$running): void {
            $running = false;
        };

        // The signal only sets the flag, so the current message always finishes.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        $connection = $this->connections->make();
        $channel = $connection->channel();

        // One message at a time for each worker.
        $channel->basic_qos(0, 1, false);

        $channel->basic_consume(
            $queue,
            callback: fn (AMQPMessage $message) => $this->handle($queue, $message, $handler),
        );

        while ($running && $channel->is_consuming()) {
            try {
                // Wake up every second to check the flag.
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // No message in this second.
            }
        }

        $channel->close();
        $connection->close();
    }

    /**
     * Runs the handler on one message, then acks or rejects it.
     *
     * @param  callable(array<string, mixed>): void  $handler
     */
    public function handle(string $queue, AMQPMessage $message, callable $handler): void
    {
        try {
            $handler($this->decode($message));
            $message->ack();
        } catch (PermanentMessageFailure $e) {
            Log::warning('Message sent to the dead letter queue', [
                'queue' => $queue,
                'reason' => $e->getMessage(),
                'body' => $message->getBody(),
            ]);

            $message->reject(requeue: false);
        } catch (Throwable $e) {
            Log::error('Message failed. It returns to the queue until the delivery limit.', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'body' => $message->getBody(),
            ]);

            // Use reject, not nack. Since RabbitMQ 4.3, only reject counts
            // toward the delivery limit. A nack can requeue a message forever.
            $message->reject(requeue: true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(AMQPMessage $message): array
    {
        try {
            $event = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PermanentMessageFailure('The message is not valid JSON.');
        }

        $isValid = is_array($event)
            && is_string($event['sensor_uid'] ?? null)
            && is_string($event['event_type'] ?? null)
            && is_numeric($event['timestamp'] ?? null)
            && is_numeric($event['received_at'] ?? null);

        if (! $isValid) {
            throw new PermanentMessageFailure('The message has missing or wrong fields.');
        }

        return $event;
    }
}
