<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Dashboard');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/documents', [DocumentController::class, 'store']);
});
