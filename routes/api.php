<?php

use App\Http\Controllers\Api\V1\RetreatController;
use App\Http\Controllers\Api\V1\RetreatLocationController;
use App\Http\Controllers\Api\V1\RetreatMessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('retreat')->group(function () {
        // Public endpoint - join retreat
        Route::post('/join', [RetreatController::class, 'join']);

        // Authenticated endpoints (require device token)
        Route::middleware('retreat.auth')->group(function () {
            Route::post('/leave', [RetreatController::class, 'leave']);
            Route::get('/status', [RetreatController::class, 'status']);
            Route::get('/waypoints', [RetreatController::class, 'waypoints']);
            Route::post('/waypoints', [RetreatController::class, 'storeWaypoint']);
            Route::post('/profile-photo', [RetreatController::class, 'updateProfilePhoto']);
            Route::delete('/profile-photo', [RetreatController::class, 'removeProfilePhoto']);

            // Location updates
            Route::post('/location', [RetreatLocationController::class, 'update']);
            Route::get('/locations', [RetreatLocationController::class, 'all']);

            // Messages
            Route::post('/messages', [RetreatMessageController::class, 'send']);
            Route::get('/messages', [RetreatMessageController::class, 'list']);
        });
    });
});

// Simple JSON health check (Forge-friendly)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'Calvary Caravan API',
        'timestamp' => now()->toIso8601String(),
    ]);
});
