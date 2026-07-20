<?php

declare(strict_types=1);

use App\Http\Controllers\SmartAuthorizeController;
use App\Http\Controllers\SmartTokenController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'OPR Vault Server',
    'fhir_metadata' => url('/api/fhir/metadata'),
]));

// SMART-on-FHIR standalone launch (session-based consent flow).
Route::get('/oauth/authorize', [SmartAuthorizeController::class, 'show']);
Route::post('/oauth/authorize/login', [SmartAuthorizeController::class, 'login'])->middleware('throttle:10,1,oauthlogin');
Route::post('/oauth/authorize/decision', [SmartAuthorizeController::class, 'decision']);

// Token endpoint: stateless, throttled; CSRF-exempt (state + PKCE protect the flow).
Route::post('/oauth/token', [SmartTokenController::class, 'token'])->middleware('throttle:30,1,oauthtoken');
