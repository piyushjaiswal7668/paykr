<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\EndImpersonationController;
use App\Http\Controllers\Api\V1\ApkController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Checkout\PageController;
use App\Http\Controllers\Checkout\SdkController;
use App\Http\Controllers\Merchant\GmailController;
use App\Http\Controllers\Realtime\ChannelController;
use App\Http\Controllers\Training\VideoController;
use App\Http\Middleware\CheckoutDeviceAccess;
use App\Http\Middleware\TrainingAccess;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::redirect('/', '/admin');

Route::get('/checkout/{id}/qr.svg', CheckoutController::class)->defaults('operation', 'qr')->middleware(CheckoutDeviceAccess::class);
Route::get('/paykr-sms-gateway.apk', ApkController::class);
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/merchant/gmail/connect', GmailController::class)->defaults('operation', 'connect');
    Route::get('/merchant/gmail/callback', GmailController::class)->defaults('operation', 'callback');
    Route::post('/merchant/gmail/test', GmailController::class)->defaults('operation', 'test');
    Route::post('/merchant/gmail/disconnect', GmailController::class)->defaults('operation', 'disconnect');
});

Route::post('/broadcasting/auth', ChannelController::class)->middleware('throttle:120,1');
Route::get('/checkout/{id}', PageController::class)->whereUuid('id');
foreach (['/paykr.js', '/sdk/paykr.js', '/sdk/paykr-checkout.js'] as $path) {
    Route::get($path, SdkController::class);
}

Route::post('/admin/end-impersonation', EndImpersonationController::class)->middleware('auth:admin');

Route::get('/training/media/{audience}/{id}', VideoController::class)
    ->where('audience', 'merchant|admin|integration')->where('id', '[0-9]{2}')
    ->middleware(['signed', TrainingAccess::class])->name('training.video');
