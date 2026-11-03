<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Stall;
use App\Services\LiveStateStore;
use Illuminate\Http\JsonResponse;

class StallStateController extends Controller
{
    public function __invoke(string $code, LiveStateStore $liveState): JsonResponse
    {
        $stall = Stall::where('code', $code)->first();

        if ($stall === null) {
            return response()->json(['message' => 'Stall not found.'], 404);
        }

        $live = $liveState->stallState($stall->id);

        if ($live !== null) {
            return response()->json(['stall' => $stall->code, 'source' => 'redis'] + $live);
        }

        // Redis has no state, so derive it from the latest session.
        $session = $stall->parkingSessions()->latest('started_at')->first();

        return response()->json([
            'stall' => $stall->code,
            'source' => 'database',
            'state' => match ($session?->status) {
                'active' => 'OCCUPIED',
                'completed' => 'VACANT',
                default => null,
            },
            'last_updated_ts' => ($session?->ended_at ?? $session?->started_at)?->timestamp,
        ]);
    }
}
