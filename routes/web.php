
<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // Página de documentos
    Route::get('/documents', function () {
        return Inertia::render('Documents/Index');
    })->name('documents.index');

    // Dados para a interface Vue
    Route::get('/documents/data', [DocumentController::class, 'index'])
        ->name('documents.data');

    Route::get('/documents/{id}', [DocumentController::class, 'show'])
        ->whereNumber('id')
        ->name('documents.show');

    Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])
        ->whereNumber('id')
        ->name('documents.destroy');

    // Upload
    Route::post('/documents', [DocumentController::class, 'store'])
        ->middleware('auth:sanctum')
        ->name('documents.store');
});
