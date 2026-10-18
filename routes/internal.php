<?php

declare(strict_types=1);
use App\Http\Controllers\Internal\ObserverController;
use App\Http\Controllers\Internal\WhatsAppStatusController;
use App\Http\Middleware\InternalObserverAuthentication;
use Illuminate\Support\Facades\Route;

Route::middleware([InternalObserverAuthentication::class, 'throttle:120,1'])->group(function (): void {
    Route::post('/internal/whatsapp/status', WhatsAppStatusController::class);
    Route::post('/internal/observations', ObserverController::class)->defaults('operation', 'observations');
    Route::get('/internal/active-window', ObserverController::class)->defaults('operation', 'active-window');
});
