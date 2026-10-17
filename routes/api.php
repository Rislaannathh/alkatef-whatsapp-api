<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {

    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // category
    Route::apiResource('categories', CategoryController::class);

    // contact
    Route::apiResource('contacts', ContactController::class);

    // campaign setup (single-submission endpoint)
    Route::post('/campaigns/setup', [CampaignController::class, 'setup']);

    // campaign contact-import status (read-only polling endpoint)
    Route::get('/campaigns/{campaign_id}/contact-import-status', [CampaignController::class, 'contactImportStatus']);

});
