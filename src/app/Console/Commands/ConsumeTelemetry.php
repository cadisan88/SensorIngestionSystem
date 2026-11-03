<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\QueueConsumer;
use App\Services\TelemetryEventProcessor;
use Illuminate\Console\Command;

class ConsumeTelemetry extends Command
{
    protected $signature = 'consume:telemetry';

    protected $description = 'Process every event: sensor readings and sensor health.';

    public function handle(QueueConsumer $consumer, TelemetryEventProcessor $processor): int
    {
        $queue = (string) config('rabbitmq.queues.telemetry.name');

        $this->info("Consuming {$queue}");
        $consumer->consume($queue, $processor->process(...));

        return self::SUCCESS;
    }
}
