<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->boolean('whatsapp_merchant_enabled')->default(false);
            $table->boolean('whatsapp_customer_enabled')->default(false);
            $table->boolean('workflow_webhooks_enabled')->default(false);
            $table->string('whatsapp_phone', 15)->nullable();
        });
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->string('booking_status', 20)->default('PENDING');
            $table->unsignedInteger('booking_version')->default(0);
            $table->string('whatsapp_phone', 15)->nullable();
            $table->boolean('whatsapp_updates_consent')->default(false);
        });
        Schema::create('whatsapp_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained('payment_sessions')->restrictOnDelete();
            $table->string('event', 60);
            $table->string('audience', 20);
            $table->text('recipient');
            $table->text('message');
            $table->string('expected_payment_status', 20)->nullable();
            $table->string('expected_booking_status', 20)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at', 3)->nullable();
            $table->timestamp('sent_at', 3)->nullable();
            $table->string('provider_id')->nullable();
            $table->timestamps(3);
            $table->unique(['payment_id', 'event', 'audience'], 'whatsapp_notification_event_unique');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notifications');
        Schema::table('payment_sessions', fn (Blueprint $table) => $table->dropColumn(['booking_status', 'booking_version', 'whatsapp_phone', 'whatsapp_updates_consent']));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn(['whatsapp_merchant_enabled', 'whatsapp_customer_enabled', 'workflow_webhooks_enabled', 'whatsapp_phone']));
    }
};
