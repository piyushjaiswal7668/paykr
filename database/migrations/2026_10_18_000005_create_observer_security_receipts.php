<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observer_request_receipts', function (Blueprint $table): void {
            $table->string('request_hash', 64)->primary();
            $table->timestamp('expires_at', 3)->index();
        });
        Schema::create('gmail_oauth_states', function (Blueprint $table): void {
            $table->string('nonce_hash', 64)->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at', 3)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_oauth_states');
        Schema::dropIfExists('observer_request_receipts');
    }
};
