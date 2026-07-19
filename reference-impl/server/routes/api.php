<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\FhirController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\GrantController;
use App\Http\Controllers\RedeemController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

Route::post('/users', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/token', [AuthController::class, 'token'])->middleware('throttle:10,1');

// Unauthenticated by design; rate-limited; no-oracle failure semantics (spec §3.3).
Route::post('/grants/redeem', RedeemController::class)->middleware('throttle:10,1');

// Witness log (spec §4.5): public by design — Merkle roots over chain heads, no PHI.
Route::get('/witness-log', fn () => response()->json([
    'entries' => app(\App\Services\WitnessService::class)->log(),
]))->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/vaults', [VaultController::class, 'store']);
    Route::get('/vaults/{vault}', [VaultController::class, 'show']);
    Route::get('/vaults/{vault}/export', [VaultController::class, 'export']);
    Route::post('/vaults/{vault}/import', [ImportController::class, 'store']);
    Route::get('/vaults/{vault}/verify', [VaultController::class, 'verify']);
    Route::get('/vaults/{vault}/audit', [VaultController::class, 'audit']);

    Route::get('/vaults/{vault}/entries', [EntryController::class, 'index']);
    Route::post('/vaults/{vault}/entries', [EntryController::class, 'store']);
    // Spec §4.1: mutation attempts are rejected with a defined OperationOutcome.
    Route::match(['put', 'patch', 'delete'], '/vaults/{vault}/entries/{entry}', [EntryController::class, 'reject']);

    Route::post('/vaults/{vault}/grants', [GrantController::class, 'store']);
    Route::post('/vaults/{vault}/grants/{grant}/revoke', [GrantController::class, 'revoke']);
    Route::post('/vaults/{vault}/share-sessions', [GrantController::class, 'shareSession']);
    Route::post('/vaults/{vault}/break-glass', [GrantController::class, 'breakGlass']);
});

// ------- FHIR R4 read surface: every vault is its own FHIR base URL -------

// Server metadata is unauthenticated per FHIR convention (no PHI).
Route::get('/fhir/metadata', [FhirController::class, 'metadata']);

Route::middleware('auth:sanctum')->prefix('/fhir/{vault}')->group(function (): void {
    Route::get('/Patient/$everything', [FhirController::class, 'everything']);
    Route::get('/{type}', [FhirController::class, 'search']);
    Route::get('/{type}/{id}', [FhirController::class, 'read']);
    // Spec §4.1 applies on the FHIR surface too.
    Route::match(['put', 'patch', 'delete'], '/{type}/{id}', [FhirController::class, 'reject']);
});
