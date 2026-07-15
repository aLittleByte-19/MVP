<?php

use App\Http\Controllers\Api\V1\Copilot\CommunicationController;
use App\Http\Controllers\Api\V1\Copilot\DocumentController;
use App\Http\Controllers\Api\V1\Copilot\StateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['mvp.identity', 'mvp.authorize', 'throttle:60,1'])
    ->group(function () {
        Route::get('/state', StateController::class)->name('state');

        Route::post('/communications', [CommunicationController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('communications.generate');

        Route::post('/documents/ocr', [DocumentController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('documents.ocr');

        Route::get('/documents/{originalDocument}/stream', [DocumentController::class, 'stream'])
            ->whereNumber('originalDocument')
            ->name('documents.stream');

        Route::delete('/documents/{subDocument}', [DocumentController::class, 'destroy'])
            ->whereNumber('subDocument')
            ->name('documents.delete');

        Route::put('/documents/{subDocument}/extracted-data', [DocumentController::class, 'updateExtractedData'])
            ->whereNumber('subDocument')
            ->name('documents.extracted-data.update');

        Route::post('/documents/{subDocument}/review', [DocumentController::class, 'markReviewed'])
            ->whereNumber('subDocument')
            ->name('documents.review');

        Route::get('/documents/{subDocument}/preview', [DocumentController::class, 'preview'])
            ->whereNumber('subDocument')
            ->name('documents.preview');
    });
