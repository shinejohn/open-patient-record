<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\GrantController;
use App\Http\Controllers\RedeemController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

Route::post('/users', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/token', [AuthController::class, 'token'])->middleware('throttle:10,1');

// Unauthenticated by design; rate-limited; no-oracle failure semantics (spec §3.3).
Route::post('/grants/redeem', RedeemController::class)->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/vaults', [VaultController::class, 'store']);
    Route::get('/vaults/{vault}', [VaultController::class, 'show']);
    Route::get('/vaults/{vault}/export', [VaultController::class, 'export']);
    Route::get('/vaults/{vault}/verify', [VaultController::class, 'verify']);
    Route::get('/vaults/{vault}/audit', [VaultController::class, 'audit']);

    Route::get('/vaults/{vault}/entries', [EntryController::class, 'index']);
    Route::post('/vaults/{vault}/entries', [EntryController::class, 'store']);
    // Spec §4.1: mutation attempts are rejected with a defined OperationOutcome.
    Route::match(['put', 'patch', 'delete'], '/vaults/{vault}/entries/{entry}', [EntryController::class, 'reject']);

    Route::post('/vaults/{vault}/grants', [GrantController::class, 'store']);
    Route::post('/vaults/{vault}/grants/{grant}/revoke', [GrantController::class, 'revoke']);
});
