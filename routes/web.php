<?php

use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
