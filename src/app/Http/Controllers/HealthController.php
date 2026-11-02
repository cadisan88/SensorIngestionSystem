<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Messaging\RabbitMqConnectionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $rabbitMq,
    ) {
    }

    /**
     * Liveness: the app is running. Checks no dependency.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'up']);
    }

    /**
     * Readiness: MariaDB, Redis and RabbitMQ answer.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::ping()),
            'rabbitmq' => $this->check('rabbitmq', function (): void {
                $this->rabbitMq->make()->close();
            }),
        ];

        $allUp = ! in_array('down', $checks, true);

        return response()->json(
            ['status' => $allUp ? 'up' : 'down', 'checks' => $checks],
            $allUp ? 200 : 503,
        );
    }

    /**
     * Runs one probe. The response only says up or down.
     * The error goes to the log, not to the client.
     */
    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (Throwable $e) {
            Log::warning("Health check failed: {$name}", ['error' => $e->getMessage()]);

            return 'down';
        }
    }
}
