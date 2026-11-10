<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;

// ========================================
// PUBLIC AUTHENTICATION ROUTES
// ========================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

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
});