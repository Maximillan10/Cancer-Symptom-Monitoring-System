<?php

use App\Http\Controllers\Web\AlertController;
use App\Http\Controllers\Web\AlertThresholdController;
use App\Http\Controllers\Web\AuthController as WebAuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\PatientPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    // Add / edit patients from the dashboard. Must stay ABOVE /patients/{patient}.
    Route::get('/patients/create', [\App\Http\Controllers\Web\PatientManageController::class, 'create'])->name('dashboard.patients.create');
    Route::post('/patients', [\App\Http\Controllers\Web\PatientManageController::class, 'store'])->name('dashboard.patients.store');
    Route::get('/patients/{patient}/edit', [\App\Http\Controllers\Web\PatientManageController::class, 'edit'])->name('dashboard.patients.edit');
    Route::put('/patients/{patient}', [\App\Http\Controllers\Web\PatientManageController::class, 'update'])->name('dashboard.patients.update');
    Route::post('/patients/{patient}/check-ins', [\App\Http\Controllers\Web\PatientManageController::class, 'storeCheckIn'])->name('dashboard.patients.checkins.store');
    // Named "dashboard.patient" so it does not clash with the API's "patients.show".
    Route::get('/patients/{patient}', [PatientPageController::class, 'show'])->name('dashboard.patient');

    // Alerts
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');

    // Each clinician's own alert thresholds
    Route::get('/settings/thresholds', [AlertThresholdController::class, 'edit'])->name('thresholds.edit');
    Route::put('/settings/thresholds', [AlertThresholdController::class, 'update'])->name('thresholds.update');
    Route::delete('/settings/thresholds', [AlertThresholdController::class, 'reset'])->name('thresholds.reset');
});
