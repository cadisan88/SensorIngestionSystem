<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\RabbitMqConnectionFactory;
use Illuminate\Console\Command;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMqSetup extends Command
{
    protected $signature = 'rabbitmq:setup';

    protected $description = 'Create the RabbitMQ exchanges, queues and bindings. Safe to run more than once.';

    public function handle(RabbitMqConnectionFactory $factory): int
    {
        $connection = $factory->make();
        $channel = $connection->channel();

        $exchange = (string) config('rabbitmq.exchange');
        $deadLetterExchange = (string) config('rabbitmq.dead_letter_exchange');

        $this->declareExchange($channel, $exchange, AMQPExchangeType::TOPIC);

        // Fanout: every failed message goes to the dead letter queue,
        // whatever its routing key is.
        $this->declareExchange($channel, $deadLetterExchange, AMQPExchangeType::FANOUT);

        foreach (['state', 'telemetry'] as $key) {
            $name = (string) config("rabbitmq.queues.{$key}.name");

            $this->declareQueue($channel, $name, [
                'x-delivery-limit' => (int) config('rabbitmq.delivery_limit'),
                'x-dead-letter-exchange' => $deadLetterExchange,
            ]);

            $channel->queue_bind($name, $exchange, (string) config("rabbitmq.queues.{$key}.binding"));
        }

        // No limit: this queue has no dead letter exchange, so a message
        // over a limit would be dropped. Failed messages must stay here.
        $deadLetterQueue = (string) config('rabbitmq.queues.dead_letter.name');
        $this->declareQueue($channel, $deadLetterQueue, ['x-delivery-limit' => -1]);
        $channel->queue_bind($deadLetterQueue, $deadLetterExchange);

        $channel->close();
        $connection->close();

        $this->info('RabbitMQ topology is ready.');

        return self::SUCCESS;
    }

    private function declareExchange(AMQPChannel $channel, string $name, string $type): void
    {
        $channel->exchange_declare($name, $type, durable: true, auto_delete: false);
    }

    /**
     * @param  array<string, int|string>  $arguments
     */
    private function declareQueue(AMQPChannel $channel, string $name, array $arguments): void
    {
        $channel->queue_declare(
            $name,
            durable: true,
            auto_delete: false,
            arguments: new AMQPTable(['x-queue-type' => 'quorum'] + $arguments),
        );
    }
}
