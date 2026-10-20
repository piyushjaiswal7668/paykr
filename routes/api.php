<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AndroidObserverController;
use App\Http\Controllers\Api\V1\ApkController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PairingTokenController;
use App\Http\Controllers\Api\V1\PaymentApiController;
use App\Http\Middleware\CheckoutDeviceAccess;
use App\Http\Middleware\MerchantApiAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', HealthController::class);

foreach (['check-user', 'send-otp', 'verify-otp', 'register', 'login', 'logout', 'switch-account'] as $operation) {
    Route::post('/v1/customer/'.$operation, CustomerAuthController::class)
        ->defaults('operation', $operation)->middleware('throttle:customer-auth');
}
Route::get('/v1/customer/me', CustomerAuthController::class)->defaults('operation', 'me')->middleware('throttle:customer-auth');

Route::middleware([MerchantApiAuthentication::class, 'throttle:merchant-api'])->group(function (): void {
    Route::post('/v1/payments', PaymentApiController::class)->defaults('operation', 'create');
    foreach (['recheck', 'request-review', 'booking-status', 'notification-preferences'] as $operation) {
        Route::post('/v1/payments/{id}/'.$operation, PaymentApiController::class)->defaults('operation', $operation)->middleware('throttle:10,1');
    }
    Route::get('/v1/payments/{id}', PaymentApiController::class)->defaults('operation', 'show');
    Route::get('/v1/payments/{id}/cod', PaymentApiController::class)->defaults('operation', 'cod');
    Route::get('/v1/orders/{orderId}/cod', PaymentApiController::class)->defaults('operation', 'order-cod');
});

Route::middleware('throttle:30,1')->group(function (): void {
    Route::post('/v1/devices/register', DeviceController::class)->defaults('operation', 'register');
    Route::post('/v1/devices/verify', DeviceController::class)->defaults('operation', 'verify');
    Route::get('/v1/devices/customer/{customerId}', DeviceController::class)->defaults('operation', 'customer');
    Route::get('/v1/devices/{id}', DeviceController::class)->defaults('operation', 'show');
});

foreach (['state', 'cod', 'stream', 'events'] as $operation) {
    Route::get('/v1/checkout/{id}/'.$operation, CheckoutController::class)->defaults('operation', $operation)->middleware([CheckoutDeviceAccess::class, 'throttle:120,1']);
}
foreach (['notification-preferences', 'recheck', 'request-review', 'bind-customer', 'retry-new-payment', 'payment-done', 'cancel', 'select-method', 'confirm-cod', 'expire', 'trigger-hook', 'end-and-restart-payment', 'end-session-and-create-new'] as $operation) {
    $middleware = in_array($operation, ['end-and-restart-payment', 'end-session-and-create-new'], true) ? [] : [CheckoutDeviceAccess::class];
    $middleware[] = in_array($operation, ['trigger-hook', 'recheck', 'request-review'], true) ? 'throttle:10,1' : 'throttle:60,1';
    Route::post('/v1/checkout/{id}/'.$operation, CheckoutController::class)->defaults('operation', $operation)->middleware($middleware);
}

Route::post('/v1/checkout/{id}/simulate-observation', CheckoutController::class)->defaults('operation', 'simulate-observation')->middleware(['auth:sanctum', 'throttle:10,1']);

Route::get('/v1/checkout/server-health', CheckoutController::class)->defaults('operation', 'server-health')->middleware('throttle:60,1');

foreach (['pollers/android/pair', 'observers/sms/pair'] as $path) {
    Route::post('/v1/'.$path, AndroidObserverController::class)->defaults('operation', 'pair')->middleware('throttle:10,1');
}
foreach (['pollers/android/events', 'observers/sms'] as $path) {
    Route::post('/v1/'.$path, AndroidObserverController::class)->defaults('operation', 'observe')->middleware('throttle:120,1');
}
foreach (['pollers/android/heartbeat', 'pollers/heartbeat', 'observers/heartbeat'] as $path) {
    Route::post('/v1/'.$path, AndroidObserverController::class)->defaults('operation', 'heartbeat')->middleware('throttle:120,1');
}
Route::get('/v1/admin/sms-gateway/download-apk', ApkController::class);
Route::post('/v1/merchant/{merchantId}/observers/pairing-token', PairingTokenController::class)->middleware(['auth:sanctum', 'throttle:10,1']);
