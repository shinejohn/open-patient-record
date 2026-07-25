<?php

declare(strict_types=1);

use App\Http\Controllers\PatientController;
use App\Http\Controllers\PracticeController;
use Illuminate\Support\Facades\Route;

Route::post('/register-practice', [PracticeController::class, 'register'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/patients', [PatientController::class, 'index']);
    Route::post('/patients', [PatientController::class, 'store']);
    Route::get('/patients/{id}/chart', [PatientController::class, 'chart']);
});
