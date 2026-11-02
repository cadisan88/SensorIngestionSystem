<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelemetryIngressController;
use App\Http\Middleware\CheckGatewayToken;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::post('/telemetry/ingress', TelemetryIngressController::class)
    ->middleware(CheckGatewayToken::class);
