<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

// --- Public auth endpoints ---
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// --- Public QR-driven driver endpoints (team UUID is the credential) ---
Route::get('/teams/{uuid}/trip', [TeamController::class, 'byUuid']);
Route::post('/teams/{uuid}/positions', [PositionController::class, 'storeByTeam']);

// --- Authenticated API ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('drivers', DriverController::class);
    Route::apiResource('vehicles', VehicleController::class);
    Route::apiResource('destinations', DestinationController::class);
    Route::apiResource('trips', TripController::class);

    // Route optimization + ETA.
    Route::post('trips/{trip}/optimize', [TripController::class, 'optimize']);

    // GPS position ingest + history.
    Route::post('vehicles/{vehicle}/positions', [PositionController::class, 'store']);
    Route::get('vehicles/{vehicle}/positions', [PositionController::class, 'index']);

    // tbss team import + listing.
    Route::get('teams', [TeamController::class, 'index']);
    Route::post('teams/import', [TeamController::class, 'import']);
    Route::post('teams/{team}/assign-vehicle', [TeamController::class, 'assignVehicle']);
});
