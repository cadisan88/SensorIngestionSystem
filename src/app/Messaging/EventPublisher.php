<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

class EventPublisher
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
    ) {
    }

    /**
     * Returns only after RabbitMQ confirms it stored the message.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws RuntimeException if the broker refuses the message
     * @throws \Throwable on a timeout or a connection error
     */
    public function publish(string $routingKey, array $body): void
    {
        $connection = $this->connections->make();

        try {
            $channel = $connection->channel();
            $channel->confirm_select();

            $confirmed = false;
            $channel->set_ack_handler(function () use (&$confirmed): void {
                $confirmed = true;
            });

            $message = new AMQPMessage(json_encode($body, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);

            $channel->basic_publish($message, (string) config('rabbitmq.exchange'), $routingKey);
            $channel->wait_for_pending_acks((float) config('rabbitmq.confirm_timeout'));

            if (! $confirmed) {
                throw new RuntimeException('RabbitMQ did not confirm the message.');
            }
        } finally {
            $connection->close();
        }
    }
}
