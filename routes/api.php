<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Staff authentication endpoints (sign-in, sign-out, session, password links,
// two-step sign-in). The controller answers 404 for anything not allowlisted.
Route::match(['GET', 'POST'], '/auth/{path?}', AuthController::class)->where('path', '.*');
