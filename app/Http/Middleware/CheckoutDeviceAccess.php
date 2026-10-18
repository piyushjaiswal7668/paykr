<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Devices\Services\DeviceAccessService;
use App\Domain\Realtime\Services\ChannelAuthorizer;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\PaymentSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutDeviceAccess
{
    public function __construct(private TenantContext $tenants, private DeviceAccessService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->header('X-Device-Id')) {
            throw new ValidationException(ErrorCode::DEVICE_REQUIRED);
        }
        $id = (string) $request->route('id');
        $merchant = $this->tenants->runUnscoped(fn () => PaymentSession::whereKey($id)->value('merchant_id')) ?? throw new ValidationException(ErrorCode::PAYMENT_NOT_FOUND);
        if ($request->isMethod('GET') && $request->route('operation') === 'state' && $this->tenants->run($merchant, fn () => app(ChannelAuthorizer::class)->customerOwns(PaymentSession::findOrFail($id), $request->bearerToken() ?? $request->cookie('paykr_session')))) {
            return $next($request);
        }
        $this->tenants->run($merchant, fn () => $this->devices->check($id, $request->header('X-Device-Id'), $request->header('X-Device-Token'), bind: false));

        return $next($request);
    }
}
