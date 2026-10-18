<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentTransitionService;
use App\Domain\Shared\Enums\ActorType;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\Actor;
use App\Models\PaymentSession;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('app.env') !== 'testing' || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'paykr-realtime-')) {
    throw new RuntimeException('This helper only operates on isolated realtime test databases.');
}
$tenants = app(TenantContext::class);
$payment = $tenants->runUnscoped(fn () => PaymentSession::findOrFail($argv[1]));
$tenants->run($payment->merchant_id, fn () => app(PaymentTransitionService::class)->transition($payment->id, PaymentStatus::CANCELLED, new Actor(ActorType::SYSTEM)));
