<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalculatorController;
use Illuminate\Support\Facades\Route;

// Staff authentication endpoints (sign-in, sign-out, session, password links,
// two-step sign-in). The controller answers 404 for anything not allowlisted.
Route::match(['GET', 'POST'], '/auth/{path?}', AuthController::class)->where('path', '.*');

// Public calculator. Each /api/fn/<name> replaces the server function of that name.
Route::get('/fn/getCalculatorCatalog', [CalculatorController::class, 'catalog']);
Route::post('/fn/calculateEstimate', [CalculatorController::class, 'estimate']);

// AI assistant. The visitor is identified by a signed cookie; limits are enforced in the database.
Route::get('/fn/getAiAvailability', [AiController::class, 'getAiAvailability']);
foreach (['sendAiMessage', 'getAiPicker', 'sendAiChoice', 'resetAiSession', 'finalizeAiSelection'] as $name) {
    Route::post("/fn/{$name}", [AiController::class, $name]);
}

// Admin Portal. Signed-in staff only; every handler then checks the caller's role.
Route::middleware('staff:session')->group(function () {
    Route::get('/fn/getSession', [AdminController::class, 'getSession']);
    Route::post('/fn/recordSignIn', [AdminController::class, 'recordSignIn']);
});
Route::middleware('staff')->group(function () {
    foreach ([
        'listUsers', 'getDashboard', 'listVersions', 'getAdminSettings', 'listPromptVersions', 'getAuditLog',
        'listCalculatorSubmissions', 'listSalesRecipients', 'getAiQuotaOverview',
    ] as $name) {
        Route::get("/fn/{$name}", [AdminController::class, $name]);
    }
    foreach ([
        'createUser', 'setUserEnabled', 'sendUserAccountEmail', 'uploadWorkbook', 'getVersionDetail', 'getPublishedData',
        'publishVersionFn', 'saveSetting', 'savePromptVersion', 'activatePromptVersion', 'getCalculatorSubmission',
        'retryMakeDelivery', 'addSalesRecipient', 'removeSalesRecipient', 'getSubmissionReportUrl', 'updateAiQuotaSettings',
    ] as $name) {
        Route::post("/fn/{$name}", [AdminController::class, $name]);
    }
});
