<?php

declare(strict_types=1);

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\EncounterController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PracticeController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::post('/register-practice', [PracticeController::class, 'register'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    // Any practice member: roster list + scheduling.
    Route::get('/patients', [PatientController::class, 'index']);
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);

    // Clinical surfaces: owner + clinician only (staff are scheduling-only).
    Route::middleware('role:owner,clinician')->group(function (): void {
        Route::post('/patients', [PatientController::class, 'store']);
        Route::post('/patients/link', [PatientController::class, 'link']);
        Route::get('/patients/{id}/chart', [PatientController::class, 'chart']);
        Route::post('/patients/{id}/encounters', [EncounterController::class, 'store']);
        Route::patch('/encounters/{id}', [EncounterController::class, 'update']);
        Route::post('/encounters/{id}/sign', [EncounterController::class, 'sign']);
    });

    // Billing + the honest transmission ledger (clinical roles).
    Route::middleware('role:owner,clinician')->group(function (): void {
        Route::post('/fee-schedule', [\App\Http\Controllers\BillingController::class, 'storeFeeItem']);
        Route::post('/invoices', [\App\Http\Controllers\BillingController::class, 'storeInvoice']);
        Route::post('/invoices/{id}/payments', [\App\Http\Controllers\BillingController::class, 'storePayment']);
        Route::post('/transmissions', [\App\Http\Controllers\TransmissionController::class, 'store']);
        Route::post('/transmissions/{id}/complete', [\App\Http\Controllers\TransmissionController::class, 'complete']);
    });

    // Staff management: owner only — authority over roles never delegates.
    Route::post('/staff', [StaffController::class, 'store'])->middleware('role:owner');
});
