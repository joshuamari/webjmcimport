<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\JmcImportController;
use App\Http\Controllers\Api\MeController;
use App\Http\Middleware\ResolveUserFromUserIdCookie;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware([ResolveUserFromUserIdCookie::class])->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/jmc/template', [JmcImportController::class, 'template']);
    Route::post('/jmc/preview', [JmcImportController::class, 'preview']);
    Route::get('/jmc/preview/{token}', [JmcImportController::class, 'show']);
    Route::get('/jmc/preview/{token}/errors', [JmcImportController::class, 'errors']);
    Route::post('/jmc/preview/{token}/confirm', [JmcImportController::class, 'confirm']);
    Route::get('/jmc/history', [JmcImportController::class, 'history']);
    Route::get('/jmc/history/{id}', [JmcImportController::class, 'historyDetail']);
    Route::post('/jmc/import', [JmcImportController::class, 'store']);
});
