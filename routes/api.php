<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalculatorController;
use Illuminate\Support\Facades\Route;

// Staff authentication endpoints (sign-in, sign-out, session, password links,
// two-step sign-in). The controller answers 404 for anything not allowlisted.
Route::match(['GET', 'POST'], '/auth/{path?}', AuthController::class)->where('path', '.*');

// Public calculator. Each /api/fn/<name> replaces the server function of that name.
Route::get('/fn/getCalculatorCatalog', [CalculatorController::class, 'catalog']);
Route::post('/fn/calculateEstimate', [CalculatorController::class, 'estimate']);
