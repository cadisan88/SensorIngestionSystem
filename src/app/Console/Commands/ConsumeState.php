<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\QueueConsumer;
use App\Services\StateEventProcessor;
use Illuminate\Console\Command;

class ConsumeState extends Command
{
    protected $signature = 'consume:state';

    protected $description = 'Process state events: parking sessions and live stall state.';

    public function handle(QueueConsumer $consumer, StateEventProcessor $processor): int
    {
        $queue = (string) config('rabbitmq.queues.state.name');

        $this->info("Consuming {$queue}");
        $consumer->consume($queue, $processor->process(...));

        return self::SUCCESS;
    }
}
