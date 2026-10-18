<?php

declare(strict_types=1);

namespace App\Domain\Checkout\Services;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentSession;

final class CheckoutPage
{
    /** Public shell: no customer, device credential, observation or private metadata.
     * @return array<string, mixed>
     */
    public function bootstrap(string $id, ?string $referrer): array
    {
        $tenants = app(TenantContext::class);
        $payment = $tenants->runUnscoped(fn () => PaymentSession::findOrFail($id));
        $merchant = Merchant::findOrFail($payment->merchant_id);
        $origin = self::origin($referrer);
        $allowed = array_values(array_filter($merchant->allowed_origins ?? [], fn (string $value): bool => self::origin($value) === $value));

        return ['state' => ['id' => $payment->id, 'paymentId' => $payment->id, 'orderId' => $payment->order_id, 'amount' => $payment->amount_paise / 100, 'currency' => $payment->currency, 'merchantName' => $merchant->business_name, 'branding' => $tenants->run($merchant->id, fn () => app(PublicBranding::class)->get())], 'parentOrigin' => in_array($origin, $allowed, true) ? $origin : null, 'allowedOrigins' => $allowed];
    }

    public static function origin(?string $value): ?string
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = $parts['port'] ?? null;

        return $parts['scheme'].'://'.$parts['host'].(($port !== null && ! (($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80))) ? ':'.$port : '');
    }
}
