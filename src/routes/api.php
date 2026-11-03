<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\StallListController;
use App\Http\Controllers\StallStateController;
use App\Http\Controllers\TelemetryIngressController;
use App\Http\Middleware\CheckGatewayToken;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::middleware(CheckGatewayToken::class)->group(function (): void {
    Route::post('/telemetry/ingress', TelemetryIngressController::class);
    Route::get('/stalls', StallListController::class);
    Route::get('/stalls/{code}/state', StallStateController::class);
});
