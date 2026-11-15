<?php

use App\Http\Controllers\Api\AccountRecoveryController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\PasswordChangeController;
use App\Http\Controllers\Api\PasswordResetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ========================================
// PUBLIC AUTHENTICATION ROUTES
// ========================================
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

// ========================================
// PUBLIC ACCOUNT RECOVERY ROUTES (email + account ID, no email delivery)
// ========================================
Route::post('/password/recovery/verify', [AccountRecoveryController::class, 'verify'])
    ->middleware('throttle:5,1');
Route::post('/password/recovery/reset', [AccountRecoveryController::class, 'reset'])
    ->middleware('throttle:5,1');

// ========================================
// PUBLIC FORGOT / RESET PASSWORD ROUTES (legacy email flow)
// ========================================
Route::post('/password/forgot', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:5,1');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1');

// ========================================
// PROTECTED ROUTES
// ========================================
Route::middleware('auth:sanctum')->group(function () {

    // AUTHENTICATED USER
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // LOGOUT
    Route::post('/logout', [AuthController::class, 'logout']);

    // ========================================
    // AUTHENTICATED PASSWORD CHANGE
    // ========================================
    Route::put('/password/change', [PasswordChangeController::class, 'update'])
        ->middleware('throttle:5,1');

    // ========================================
    // DASHBOARD
    // ========================================
    Route::get('/dashboard', [ContactController::class, 'dashboard']);

    // ========================================
    // FAVORITES
    // ========================================
    Route::get('/contacts/favorites', [ContactController::class, 'favorites']);
    Route::patch('/contacts/{id}/favorite', [ContactController::class, 'toggleFavorite']);

    // ========================================
    // CONTACT CRUD
    // ========================================
    Route::apiResource('contacts', ContactController::class);

    // ========================================
    // ADMIN USER MANAGEMENT
    // ========================================
    Route::prefix('admin')
        ->middleware(['admin', 'throttle:60,1'])
        ->group(function () {
            Route::get('/users', [UserManagementController::class, 'index']);
            Route::get('/users/{user}', [UserManagementController::class, 'show']);
            Route::put('/users/{user}', [UserManagementController::class, 'update']);
            Route::delete('/users/{user}', [UserManagementController::class, 'destroy']);
            Route::put('/users/{user}/password', [UserManagementController::class, 'resetPassword']);
        });
});
