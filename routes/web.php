<?php

use App\Poc\Controllers\AdminController;
use App\Poc\Controllers\AppApiController;
use App\Poc\Controllers\PocController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PocController::class, 'index'])->name('poc.app');

Route::redirect('/app', '/');
Route::redirect('/login', '/')->name('login');

Route::prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/settings', [AdminController::class, 'save'])->name('save');
        Route::post('/simulation-defaults', [AdminController::class, 'useSimulationDefaults'])->name('simulation');
        Route::post('/clear-credentials', [AdminController::class, 'clearAwsCredentials'])->name('clear-credentials');
        Route::post('/reset-data', [AdminController::class, 'resetData'])->name('reset-data');
    });

Route::prefix('poc')
    ->name('poc.')
    ->group(function () {
        Route::get('/documents/{subDocument}/preview', [AppApiController::class, 'previewSubDocument'])
            ->whereNumber('subDocument')
            ->name('documents.preview');

        Route::prefix('api')
            ->name('api.')
            ->middleware('throttle:60,1')
            ->group(function () {
                Route::get('/state', [AppApiController::class, 'state'])->name('state');
                Route::post('/communications', [AppApiController::class, 'generateCommunication'])
                    ->middleware('throttle:20,1')
                    ->name('communications.generate');
                Route::post('/documents/ocr', [AppApiController::class, 'runDocumentOcr'])
                    ->middleware('throttle:20,1')
                    ->name('documents.ocr');
                Route::get('/documents/{originalDocument}/stream', [AppApiController::class, 'streamDocumentProcessing'])
                    ->whereNumber('originalDocument')
                    ->name('documents.stream');
                Route::delete('/documents/{subDocument}', [AppApiController::class, 'deleteSubDocument'])
                    ->whereNumber('subDocument')
                    ->name('documents.delete');
            });
    });
