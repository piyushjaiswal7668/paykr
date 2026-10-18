<?php

declare(strict_types=1);
use App\Domain\Checkout\Services\CheckoutPage;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantBranding;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);
it('escapes the public checkout shell and validates the exact parent origin without binding a device', function () {
    $merchant = Merchant::factory()->create(['allowed_origins' => ['https://shop.example.com'], 'business_name' => '<script>alert(1)</script>']);
    $payment = app(TenantContext::class)->run($merchant->id, function () {
        MerchantBranding::factory()->create(['app_name' => '</script><script>EVIL</script>']);

        return PaymentSession::factory()->create(['metadata' => ['secret' => 'PRIVATE']]);
    });
    $this->withHeader('Referer', 'https://shop.example.com/cart')->get('/checkout/'.$payment->id)->assertOk()->assertSee('checkout-bootstrap', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('</script><script>EVIL</script>', false)->assertDontSee('PRIVATE')->assertHeader('Cache-Control', 'no-store, private');
    $policy = $this->get('/checkout/'.$payment->id)->headers->get('Content-Security-Policy');
    expect($policy)->toContain("script-src 'self'", "object-src 'none'", "base-uri 'none'", "frame-ancestors 'self' https://shop.example.com");
    $data = app(CheckoutPage::class)->bootstrap($payment->id, 'https://shop.example.com.evil/cart');
    expect($data['parentOrigin'])->toBeNull()->and($payment->fresh()->device_id)->toBeNull();
    expect(app(CheckoutPage::class)->bootstrap($payment->id, 'https://shop.example.com/cart')['parentOrigin'])->toBe('https://shop.example.com');
    expect(CheckoutPage::origin('javascript:alert(1)'))->toBeNull()->and(CheckoutPage::origin('https://u:p@shop.example.com'))->toBeNull()->and(CheckoutPage::origin('https://shop.example.com:443/a'))->toBe('https://shop.example.com');
    expect(CheckoutPage::origin("https://shop.example.com; script-src 'unsafe-inline'"))->toBeNull();
    $this->get('/checkout/11111111-1111-4111-8111-111111111111')->assertNotFound();
});
it('serves every SDK alias with CORS and verifies the generated integrity hash', function () {
    $bytes = file_get_contents(public_path('sdk/paykr.min.js'));
    foreach (['/paykr.js', '/sdk/paykr.js', '/sdk/paykr-checkout.js'] as $path) {
        $this->get($path)->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
    }
    expect(json_decode(file_get_contents(public_path('sdk/integrity.json')), true)['integrity'])->toBe('sha384-'.base64_encode(hash('sha384', $bytes, true)));
});
it('accepts the SDK key header only for its allowed merchant origin', function () {
    Bus::fake();
    $merchant = Merchant::factory()->create(['allowed_origins' => ['https://shop.example.com']]);
    app(TenantContext::class)->run($merchant->id, fn () => MerchantApiKey::factory()->create(['key_hash' => hash('sha256', 'pk_live_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), 'type' => 'publishable', 'mode' => 'live']));
    app(TenantContext::class)->run($merchant->id, fn () => PaymentReceivingAccount::factory()->active()->create());
    $this->withHeaders(['X-Merchant-Key' => 'pk_live_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Origin' => 'https://evil.example'])->postJson('/api/v1/payments', ['orderId' => 'SDK-1', 'amount' => 10])->assertForbidden();
    $this->withHeader('Origin', 'https://shop.example.com')->postJson('/api/v1/payments', ['orderId' => 'SDK-1', 'amount' => 10])->assertCreated();
    $this->options('/api/v1/payments', [], ['Origin' => 'https://shop.example.com', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'x-merchant-key,content-type'])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', '*');
});
