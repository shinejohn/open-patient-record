<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DelegateController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\FhirController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\GrantController;
use App\Http\Controllers\RedeemController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

// Distinct throttle buckets per route (3rd arg): unauthenticated throttles key by
// IP, and a shared bucket would let activity on one endpoint starve another.
Route::post('/users', [AuthController::class, 'register'])->middleware('throttle:10,1,register');
Route::post('/token', [AuthController::class, 'token'])->middleware('throttle:10,1,login');

// Unauthenticated by design; rate-limited; no-oracle failure semantics (spec §3.3).
Route::post('/grants/redeem', RedeemController::class)->middleware('throttle:10,1,redeem');

// Passkeys (WebAuthn) — login surfaces are unauthenticated, throttled, no-oracle.
Route::post('/webauthn/login/options', [\App\Http\Controllers\WebAuthnController::class, 'loginOptions'])->middleware('throttle:20,1,pklogin');
Route::post('/webauthn/login', [\App\Http\Controllers\WebAuthnController::class, 'login'])->middleware('throttle:20,1,pklogin');
Route::post('/account/recover', [\App\Http\Controllers\RecoveryController::class, 'request'])->middleware('throttle:5,1,recover');
Route::post('/account/recover/complete', [\App\Http\Controllers\RecoveryController::class, 'complete'])->middleware('throttle:5,1,recover');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/webauthn/register/options', [\App\Http\Controllers\WebAuthnController::class, 'registerOptions']);
    Route::post('/webauthn/register', [\App\Http\Controllers\WebAuthnController::class, 'register']);
    Route::post('/webauthn/credentials/{credential}/revoke', [\App\Http\Controllers\WebAuthnController::class, 'revoke']);
});

// Witness log (spec §4.5): public by design — Merkle roots over chain heads, no PHI.
Route::get('/witness-log', fn () => response()->json([
    'entries' => app(\App\Services\WitnessService::class)->log(),
]))->middleware('throttle:60,1,witness');

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

    Route::get('/vaults/{vault}/delegates', [DelegateController::class, 'index']);
    Route::post('/vaults/{vault}/delegates', [DelegateController::class, 'store']);
    Route::post('/vaults/{vault}/delegates/{delegate}/revoke', [DelegateController::class, 'revoke']);

    Route::get('/vaults/{vault}/witness-proof', [VaultController::class, 'witnessProof']);
});

// ------- FHIR R4 read surface: every vault is its own FHIR base URL -------

// Server metadata is unauthenticated per FHIR convention (no PHI).
Route::get('/fhir/metadata', [FhirController::class, 'metadata']);
// SMART discovery + id_token keys (public server metadata, no PHI).
Route::get('/fhir/{vault}/.well-known/smart-configuration', [\App\Http\Controllers\SmartTokenController::class, 'smartConfiguration']);
Route::get('/oauth/jwks', [\App\Http\Controllers\SmartTokenController::class, 'jwks']);

Route::middleware('auth:sanctum')->prefix('/fhir/{vault}')->group(function (): void {
    Route::get('/Patient/$everything', [FhirController::class, 'everything']);
    // Bulk FHIR async pattern (subject/delegate only).
    Route::get('/Patient/$export', [\App\Http\Controllers\BulkExportController::class, 'kickoff']);
    Route::get('/$export-status/{job}', [\App\Http\Controllers\BulkExportController::class, 'status']);
    Route::delete('/$export-status/{job}', [\App\Http\Controllers\BulkExportController::class, 'cancel']);
    Route::get('/$export-file/{job}/{file}', [\App\Http\Controllers\BulkExportController::class, 'file']);
    Route::get('/{type}', [FhirController::class, 'search']);
    Route::get('/{type}/{id}', [FhirController::class, 'read']);
    // Spec §4.1 applies on the FHIR surface too.
    Route::match(['put', 'patch', 'delete'], '/{type}/{id}', [FhirController::class, 'reject']);
});
