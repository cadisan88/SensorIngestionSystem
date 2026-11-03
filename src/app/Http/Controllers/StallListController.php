<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Stall;
use Illuminate\Http\JsonResponse;

class StallListController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $stalls = Stall::with('sensors')->orderBy('code')->get();

        return response()->json([
            'stalls' => $stalls->map(fn (Stall $stall) => [
                'code' => $stall->code,
                'sensors' => $stall->sensors->pluck('sensor_uid'),
            ]),
        ]);
    }
}
