<?php

use App\Http\Controllers\Api\V1\DeviceTelemetryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GPS Device Telemetry API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/device')->group(function () {
    Route::match(['get', 'post'], '/telemetry', [DeviceTelemetryController::class, 'ingest'])->name('api.v1.device.telemetry');
    Route::match(['get', 'post'], '/heartbeat', [DeviceTelemetryController::class, 'heartbeat'])->name('api.v1.device.heartbeat');
});
