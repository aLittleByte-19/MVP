<?php

use App\Poc\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
