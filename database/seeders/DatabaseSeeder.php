<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Merchants\Enums\MerchantRole;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\PaymentReceivingAccount;
use App\Models\User;
use App\Support\ProductionSecrets;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        ProductionSecrets::assertSafe();
        $email = config('paykr.admin.email');
        $password = config('paykr.admin.password');
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Configure PAYKR_ADMIN_EMAIL and a PAYKR_ADMIN_PASSWORD of at least 12 characters before seeding.');
        }
        $user = User::firstOrCreate(['email' => $email], ['name' => 'Platform Admin', 'password' => $password]);
        $user->forceFill(['is_platform_admin' => true])->save();
        if (app()->environment(['local', 'testing'])) {
            $merchant = Merchant::firstOrCreate(['id' => '00000000-0000-0000-0000-000000000001'], ['business_name' => 'Paykr Demo Store', 'slug' => 'demo-store', 'allowed_origins' => ['http://localhost:5000'], 'webhook_url' => config('paykr.demo.webhook_url'), 'webhook_secret' => config('paykr.demo.webhook_secret')]);
            app(TenantContext::class)->run($merchant->id, function () use ($merchant): void {
                if (! PaymentReceivingAccount::query()->exists()) {
                    PaymentReceivingAccount::forceCreate(['id' => '00000000-0000-0000-0000-000000000002', 'merchant_id' => $merchant->id, 'upi_id' => 'merchant@upi', 'payee_name' => $merchant->business_name, 'is_active' => true]);
                }
            });
            $merchant->users()->syncWithoutDetaching([$user->id => ['role' => MerchantRole::OWNER->value]]);
        }
    }
}
