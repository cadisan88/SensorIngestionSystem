<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TelemetryIngressRequest;
use App\Messaging\EventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelemetryIngressController extends Controller
{
    public function __invoke(TelemetryIngressRequest $request, EventPublisher $publisher): JsonResponse
    {
        $event = $request->validated();
        $trackingId = (string) Str::uuid();

        $body = $event + [
            'tracking_id' => $trackingId,
            'received_at' => now()->timestamp,
        ];

        try {
            $publisher->publish(config("rabbitmq.routing_keys.{$event['event_type']}"), $body);
        } catch (Throwable $e) {
            Log::error('Could not publish event', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not queue the event. Try again.'], 503);
        }

        return response()->json(['tracking_id' => $trackingId], 202);
    }
}
