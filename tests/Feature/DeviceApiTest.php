<?php

declare(strict_types=1);
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);
beforeEach(fn () => Cache::flush());
it('registers canonical keys, keeps tokens private, and verifies device signatures', function () {
    $key = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $public = openssl_pkey_get_details($key)['key'];
    $device = $this->postJson('/api/v1/devices/register', ['publicKey' => $public, 'signals' => ['platform' => 'MacIntel']])->assertOk();
    $token = $device->json('deviceToken');
    $id = $device->json('deviceId');
    expect(Device::first()->device_token_hash)->toBe(hash('sha256', $token));
    $this->postJson('/api/v1/devices/register', ['publicKey' => $public])->assertOk()->assertJsonPath('deviceToken', null)->assertJsonPath('isExisting', true)->assertJsonPath('deviceId', $id);
    $this->postJson('/api/v1/devices/verify', ['deviceId' => $id, 'deviceToken' => $token])->assertOk()->assertJsonPath('tokenValid', true);
    openssl_sign('proof', $signature, $key, OPENSSL_ALGO_SHA256);
    $this->postJson('/api/v1/devices/verify', ['deviceId' => $id, 'payload' => 'proof', 'signature' => base64_encode($signature)])->assertOk()->assertJsonPath('signatureValid', true);
    $this->getJson('/api/v1/devices/'.$id)->assertUnauthorized();
    Device::first()->update(['status' => 'REVOKED']);
    $this->postJson('/api/v1/devices/verify', ['deviceId' => $id, 'deviceToken' => $token])->assertOk()->assertJsonPath('valid', false);
    $this->postJson('/api/v1/devices/register', ['publicKey' => 12])->assertStatus(422);
    $this->postJson('/api/v1/devices/register', ['publicKey' => 'invalid'])->assertStatus(422);
});

it('permits device inventory only to its customer or a persisted platform administrator', function () {
    $owner = Customer::factory()->create();
    $stranger = Customer::factory()->create();
    $device = Device::factory()->create(['customer_id' => $owner->id]);
    CustomerSession::factory()->create(['customer_id' => $owner->id, 'token_hash' => hash('sha256', 'owner-token')]);
    CustomerSession::factory()->create(['customer_id' => $stranger->id, 'token_hash' => hash('sha256', 'stranger-token')]);
    $this->withToken('owner-token')->getJson('/api/v1/devices/'.$device->id)->assertOk();
    $this->getJson('/api/v1/devices/customer/'.$owner->id)->assertOk()->assertJsonCount(1, 'devices');
    $this->withToken('stranger-token')->getJson('/api/v1/devices/'.$device->id)->assertForbidden();
    $this->getJson('/api/v1/devices/customer/'.$owner->id)->assertForbidden();
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/devices/'.$device->id)->assertOk();
    $this->getJson('/api/v1/devices/customer/'.$owner->id)->assertOk();
});

it('preserves the existing device token on authenticated registration and rejects concurrent registration', function () {
    $key = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $public = openssl_pkey_get_details($key)['key'];
    $result = $this->postJson('/api/v1/devices/register', ['publicKey' => $public])->assertOk();
    $this->postJson('/api/v1/devices/register', ['publicKey' => $public, 'deviceToken' => $result->json('deviceToken')])
        ->assertOk()->assertJsonPath('deviceToken', $result->json('deviceToken'))->assertJsonPath('isExisting', true);
    $lock = Cache::lock('device-register:'.hash('sha256', $public), 10);
    expect($lock->get())->toBeTrue();
    try {
        $this->postJson('/api/v1/devices/register', ['publicKey' => $public])->assertStatus(429);
    } finally {
        $lock->release();
    }
    expect(Device::count())->toBe(1);
});
