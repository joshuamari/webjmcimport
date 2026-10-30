<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\JmcImportController;
use App\Http\Controllers\Api\MeController;
use App\Http\Middleware\ResolveUserFromUserIdCookie;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware([ResolveUserFromUserIdCookie::class])->group(function () {
    Route::get('/me', MeController::class);
    Route::post('/jmc/import', [JmcImportController::class, 'store']);
});
