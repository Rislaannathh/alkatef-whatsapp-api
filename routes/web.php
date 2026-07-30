<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CategoryController;


Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

Route::get('/csrf-token', [CategoryController::class, 'csrfToken']);

require __DIR__.'/auth.php';
