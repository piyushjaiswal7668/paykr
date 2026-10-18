<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Management\Services\PanelAuditContext;
use App\Domain\Payments\Events\PaymentActionTriggered;
use App\Domain\Payments\Events\PaymentCancelled;
use App\Domain\Payments\Events\PaymentExpired;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Events\PaymentMethodSwitched;
use App\Domain\Payments\Events\PaymentStateChanged;
use App\Domain\Payments\Events\PaymentSucceeded;
use App\Domain\Payments\Events\QueueUpdated;
use App\Domain\Payments\Events\SessionActivated;
use App\Domain\Payments\Listeners\PromotePaymentQueue;
use App\Domain\Payments\Listeners\SchedulePaymentDeadline;
use App\Domain\Realtime\Listeners\PublishPaymentUpdate;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Events\PaymentFraudBlocked;
use App\Domain\Verification\Events\PaymentLateDetected;
use App\Domain\Verification\Events\PaymentLevel1Verified;
use App\Domain\Verification\Events\PaymentVerificationUpgraded;
use App\Support\ProductionSecrets;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PanelAuditContext::class);
    }

    public function boot(): void
    {
        ProductionSecrets::assertSafe();
        RateLimiter::for('merchant-api', function (Request $request) {
            $key = $request->header('X-Api-Key') ?? $request->header('X-Merchant-Key') ?? $request->bearerToken() ?? $request->header('X-Merchant-Id', '');

            return Limit::perMinute(60)->by(hash('sha256', $key).':'.$request->ip());
        });
        RateLimiter::for('customer-auth', function (Request $request) {
            $operation = $request->route('operation');
            $limit = match ($operation) {
                'check-user' => 30,
                'verify-otp' => 10,
                'login', 'register' => 5,
                default => 60,
            };
            $minutes = in_array($operation, ['login', 'register'], true) ? 15 : 1;

            return Limit::perMinutes($minutes, $limit)->by($operation.':'.$request->ip());
        });
        Event::listen([
            PaymentStateChanged::class, SessionActivated::class, PaymentSucceeded::class,
            PaymentFailed::class, PaymentExpired::class, PaymentCancelled::class,
            PaymentMethodSwitched::class,
            PaymentActionTriggered::class,
            QueueUpdated::class,
            PaymentLevel1Verified::class,
            PaymentVerificationUpgraded::class,
            PaymentLateDetected::class,
            PaymentFraudBlocked::class,
        ], PublishPaymentUpdate::class);
        Event::listen([SessionActivated::class, PaymentStateChanged::class], SchedulePaymentDeadline::class);
        Event::listen([
            PaymentSucceeded::class,
            PaymentFailed::class,
            PaymentExpired::class,
            PaymentCancelled::class,
        ], PromotePaymentQueue::class);
    }
}
