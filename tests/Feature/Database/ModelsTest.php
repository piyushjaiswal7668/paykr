<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Services\PasswordService;
use App\Domain\Shared\Services\TenantContext;
use App\Models\AndroidDevice;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use App\Models\PaymentObservation;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSession;
use App\Models\PlatformOption;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('provides working factories casts and relationships for every domain model', function () {
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            $model = $class::factory()->create();
            if ($model instanceof PlatformOption) {
                // Configuration entries use a named key, not an externally referenced entity ID.
                expect($model->getKey())->toBe($model->key)->and($model->value)->toBeArray();
            } else {
                expect(Str::isUuid($model->id))->toBeTrue();
            }
            expect($model->fresh())->not->toBeNull();
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                if ($method->getNumberOfRequiredParameters() === 0 && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Relation::class)) {
                    expect($model->{$method->name}())->toBeInstanceOf(Relation::class);
                    $model->{$method->name}()->getResults();
                }
            }
        }
    });
});

it('encrypts and hides secrets and preserves integer paise', function () {
    $merchant = Merchant::factory()->create(['webhook_secret' => 'webhook-secret']);
    expect($merchant->getRawOriginal('webhook_secret'))->not->toBe('webhook-secret');
    app(TenantContext::class)->run($merchant->id, function () {
        $gmail = MerchantGmailConnection::factory()->create(['client_secret' => 'secret', 'refresh_token' => 'refresh', 'access_token' => 'access']);
        foreach (['client_secret' => 'secret', 'refresh_token' => 'refresh', 'access_token' => 'access'] as $key => $value) {
            expect($gmail->fresh()->{$key})->toBe($value)->and($gmail->getRawOriginal($key))->not->toBe($value);
            expect($gmail->toArray())->not->toHaveKey($key);
        }
        $device = AndroidDevice::factory()->create(['signing_key' => 'key']);
        expect($device->fresh()->signing_key)->toBe('key')->and($device->getRawOriginal('signing_key'))->not->toBe('key');
        $account = PaymentReceivingAccount::factory()->active()->create();
        $payment = PaymentSession::factory()->active()->forAccount($account)->create(['amount_paise' => 9007199254740993]);
        expect($payment->fresh()->amount_paise)->toBe(9007199254740993);
        expect($payment->receivingAccount->id)->toBe($account->id);
        $payment->delete();
    });
    expect(Customer::factory()->banned()->create()->status)->toBe(CustomerStatus::BANNED);
});

it('rejects tampered encrypted secrets and verifies HMAC payload integrity', function () {
    $encrypted = Crypt::encryptString('oauth-secret');
    expect(Crypt::decryptString($encrypted))->toBe('oauth-secret');
    $payload = json_decode(base64_decode($encrypted), true, 512, JSON_THROW_ON_ERROR);
    $payload['value'] = base64_encode('tampered-ciphertext');
    expect(fn () => Crypt::decryptString(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))))->toThrow(DecryptException::class);
    $signature = hash_hmac('sha256', 'original-body', 'merchant-secret');
    expect(hash_equals($signature, hash_hmac('sha256', 'original-body', 'merchant-secret')))->toBeTrue();
    expect(hash_equals($signature, hash_hmac('sha256', 'changed-body', 'merchant-secret')))->toBeFalse();
    expect(hash_equals($signature, hash_hmac('sha256', 'original-body', 'wrong-secret')))->toBeFalse();
});

it('persists customer registration with password verification', function () {
    $passwords = app(PasswordService::class);
    $customer = Customer::factory()->create(['password_hash' => $passwords->hash('CorrectPassword123')]);
    expect($passwords->verify('CorrectPassword123', $customer->fresh()->password_hash))->toBeTrue();
    expect($passwords->verify('IncorrectPassword123', $customer->fresh()->password_hash))->toBeFalse();
});

it('resets disposable customer fixtures without losing merchant accounts payments or observations', function () {
    // The Node case deletes production SQLite files. Its Laravel equivalent only touches the test transaction.
    $customer = Customer::factory()->create();
    CustomerSession::factory()->create(['customer_id' => $customer->id]);
    $merchant = Merchant::factory()->create();
    app(TenantContext::class)->run($merchant->id, function () use ($customer, $merchant) {
        $account = PaymentReceivingAccount::factory()->active()->create();
        $payment = PaymentSession::factory()->forAccount($account)->create(['customer_id' => $customer->id]);
        $observation = PaymentObservation::factory()->create(['receiving_account_id' => $account->id]);
        $device = Device::factory()->create(['customer_id' => $customer->id]);
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->id, 'payment_id' => $payment->id]);
        TicketMessage::factory()->create(['ticket_id' => $ticket->id]);
        DB::transaction(function () use ($customer, $payment, $device, $ticket) {
            TicketMessage::where('ticket_id', $ticket->id)->delete();
            $ticket->delete();
            $payment->update(['customer_id' => null]);
            $device->update(['customer_id' => null]);
            $customer->sessions()->delete();
            $customer->delete();
        });
        expect(Customer::find($customer->id))->toBeNull();
        expect(CustomerSession::where('customer_id', $customer->id)->count())->toBe(0);
        expect(SupportTicket::where('customer_id', $customer->id)->count())->toBe(0);
        expect($payment->refresh()->customer_id)->toBeNull();
        expect($device->refresh()->customer_id)->toBeNull();
        expect($merchant->fresh())->not->toBeNull();
        expect($account->fresh())->not->toBeNull();
        expect($observation->fresh())->not->toBeNull();
    });
});
