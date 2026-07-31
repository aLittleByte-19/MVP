<?php

use App\Http\Controllers\Api\V1\Copilot\CommunicationController;
use App\Http\Controllers\Api\V1\Copilot\CommunicationCoverController;
use App\Http\Controllers\Api\V1\Copilot\CommunicationExportController;
use App\Http\Controllers\Api\V1\Copilot\CommunicationRatingController;
use App\Http\Controllers\Api\V1\Copilot\CommunicationStreamController;
use App\Http\Controllers\Api\V1\Copilot\DocumentController;
use App\Http\Controllers\Api\V1\Copilot\DocumentPreviewController;
use App\Http\Controllers\Api\V1\Copilot\DocumentReviewController;
use App\Http\Controllers\Api\V1\Copilot\SendMessageController;
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

        Route::get('/communications/{communication}/stream', [CommunicationStreamController::class, 'stream'])
            ->whereNumber('communication')
            ->name('communications.stream');

        Route::post('/communications/{communication}/regenerate', [CommunicationController::class, 'regenerate'])
            ->whereNumber('communication')
            ->middleware('throttle:20,1')
            ->name('communications.regenerate');

        Route::post('/communications/{communication}/discard', [CommunicationController::class, 'discard'])
            ->whereNumber('communication')
            ->middleware('throttle:20,1')
            ->name('communications.discard');

        Route::delete('/communications/{communication}', [CommunicationController::class, 'destroy'])
            ->whereNumber('communication')
            ->name('communications.delete');

        // POST e non PUT: PHP popola $_FILES solo sulle richieste POST, su PUT
        // il body multipart resterebbe grezzo e il file non arriverebbe mai.
        Route::post('/communications/{communication}/cover-image', [CommunicationCoverController::class, 'updateCoverImage'])
            ->whereNumber('communication')
            ->middleware('throttle:20,1')
            ->name('communications.cover-image.update');

        Route::delete('/communications/{communication}/cover-image', [CommunicationCoverController::class, 'removeCoverImage'])
            ->whereNumber('communication')
            ->name('communications.cover-image.remove');

        // Lo storico rende fino a 10 copertine per pagina: il limite di gruppo
        // (60/min) verrebbe consumato da una manciata di visite.
        Route::get('/communications/{communication}/cover-image', [CommunicationCoverController::class, 'coverImage'])
            ->whereNumber('communication')
            ->middleware('throttle:120,1')
            ->name('communications.cover-image.show');

        // Piu' stretto del bucket di gruppo (60/min): sono le risposte piu'
        // pesanti dell'API (il PDF completo, copertina inclusa, a ogni hit) e
        // nascono da un click umano, non da un render di lista. Il costo dompdf
        // e' gia' limitato dalla copia materializzata e dal 304 su ETag: qui si
        // limita il traffico, non la CPU.
        Route::get('/communications/{communication}/preview', [CommunicationExportController::class, 'preview'])
            ->whereNumber('communication')
            ->middleware('throttle:30,1')
            ->name('communications.preview');

        Route::get('/communications/{communication}/export', [CommunicationExportController::class, 'export'])
            ->whereNumber('communication')
            ->middleware('throttle:30,1')
            ->name('communications.export');

        Route::post('/communications/{communication}/rating', [CommunicationRatingController::class, 'rate'])
            ->whereNumber('communication')
            ->middleware('throttle:30,1')
            ->name('communications.rate');

        Route::put('/communications/{communication}', [CommunicationController::class, 'update'])
            ->whereNumber('communication')
            ->name('communications.update');

        Route::get('/documents', [DocumentController::class, 'index'])
            ->name('documents.index');

        Route::post('/documents/ocr', [DocumentController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('documents.ocr');

        Route::get('/documents/{originalDocument}/stream', [DocumentController::class, 'stream'])
            ->whereNumber('originalDocument')
            ->name('documents.stream');

        Route::delete('/documents/{subDocument}', [DocumentController::class, 'destroy'])
            ->whereNumber('subDocument')
            ->name('documents.delete');

        Route::put('/documents/{subDocument}/extracted-data', [DocumentReviewController::class, 'updateExtractedData'])
            ->whereNumber('subDocument')
            ->name('documents.extracted-data.update');

        Route::post('/documents/{subDocument}/review', [DocumentReviewController::class, 'markReviewed'])
            ->whereNumber('subDocument')
            ->name('documents.review');

        Route::get('/documents/{subDocument}/preview', [DocumentPreviewController::class, 'preview'])
            ->whereNumber('subDocument')
            ->name('documents.preview');

        Route::get('/documents/{subDocument}/send-preview', [SendMessageController::class, 'sendPreview'])
            ->whereNumber('subDocument')
            ->name('documents.send-preview');

        Route::get('/documents/{subDocument}/send-export', [SendMessageController::class, 'sendExport'])
            ->whereNumber('subDocument')
            ->name('documents.send-export');

        Route::put('/documents/{subDocument}/send-message', [SendMessageController::class, 'updateSendMessage'])
            ->whereNumber('subDocument')
            ->name('documents.send-message.update');
    });
