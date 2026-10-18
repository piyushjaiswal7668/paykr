<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_observations', function (Blueprint $table): void {
            $table->timestamp('verified_at', 3)->nullable()->index();
        });
        Schema::create('gmail_processed_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->timestamp('processed_at', 3);
            $table->unique(['merchant_id', 'message_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_processed_messages');
        Schema::table('payment_observations', function (Blueprint $table): void {
            $table->dropIndex(['verified_at']);
            $table->dropColumn('verified_at');
        });
    }
};
