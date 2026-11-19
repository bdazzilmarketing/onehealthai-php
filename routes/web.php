<?php

use App\Http\Controllers\WebController;
use Illuminate\Support\Facades\Route;

// Every address outside /api/ is a web page (or "Page not found").
Route::get('/{path?}', WebController::class)->where('path', '^(?!api(/|$)).*');
