<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\SymptomSubmissionController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

// Authenticated (Sanctum bearer token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('patients', PatientController::class);

    Route::get('/patients/{patient}/symptom-submissions', [SymptomSubmissionController::class, 'index']);
    Route::post('/symptom-submissions', [SymptomSubmissionController::class, 'store']);
});
