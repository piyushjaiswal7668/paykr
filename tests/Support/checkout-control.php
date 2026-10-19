<?php

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Management\Services\MerchantOperations;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Services\AttributionEngine;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Models\MerchantPaymentSettings;
use App\Models\PaymentObservation;
use App\Models\PaymentSession;
use App\Models\SupportTicket;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_starts_with(basename(config('database.connections.sqlite.database')), 'paykr-checkout-')) {
    throw new RuntimeException('Test isolation failed');
}
config(['queue.connections.redis.driver' => 'sync']);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
app(TenantContext::class)->run($input['merchantId'], function () use ($input) {
    $payment = isset($input['id']) ? PaymentSession::findOrFail($input['id']) : null;
    switch ($input['operation']) {
        case 'finalize': app(AttributionEngine::class)->finalizeLevel1($payment->id);
            break;
        case 'deadline': $payment->update(['expires_at' => now()->addSeconds($input['seconds'])]);
            break;
        case 'return-url': $payment->update(['return_url' => $input['url']]);
            break;
        case 'mode':MerchantPaymentSettings::firstOrFail()->update(['selling_mode' => $input['mode'], 'cod_enabled' => true, 'cash_enabled' => true, 'card_enabled' => true]);
            break;
        case 'counter':app(MerchantOperations::class)->execute(new PanelCommand($input['ownerId'], $input['merchantId'], 'counter-confirm', $payment->id));
            break;
        case 'fraud':$result = app(VerificationPipeline::class)->process(new VerificationData('SMS', 'Rs 10 credited UTR 999999999991', $payment->receiving_account_id, sender: $payment->customer->mobile_number, paymentSessionId: $payment->id, customerId: $payment->customer_id));
            echo json_encode($result->data);
            break;
        case 'inspect':echo json_encode(['status' => $payment->status->value, 'tickets' => SupportTicket::where('payment_id', $payment->id)->count(), 'reviews' => PaymentObservation::where('attribution_status', 'LATE_UNMATCHED')->count()]);
            break;
        default:throw new RuntimeException('Unknown fixture operation');
    }
});
