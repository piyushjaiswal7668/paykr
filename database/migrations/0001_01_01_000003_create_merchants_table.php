<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('business_name');
            $table->string('slug')->unique();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('status')->default(MerchantStatus::ACTIVE->value);
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('allowed_origins')->nullable();
            $table->timestamps(3);
        });
        Schema::create('merchant_users', function (Blueprint $table): void {
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps(3);
            $table->unique(['merchant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_users');
        Schema::dropIfExists('merchants');
    }
};
