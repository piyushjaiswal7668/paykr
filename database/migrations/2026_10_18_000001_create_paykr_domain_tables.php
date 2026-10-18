<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->longText('legacy_settings')->nullable();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('mobile_number');
            $table->text('password_hash')->nullable();
            $table->boolean('password_needs_rehash')->default(false);
            $table->boolean('is_phone_verified')->default(false);
            $table->string('status')->default('ACTIVE');
            $table->text('ban_reason')->nullable();
            $table->text('last_device')->nullable();
            $table->timestamp('banned_at', 3)->nullable();
            $table->timestamp('unbanned_at', 3)->nullable();
            $table->timestamp('last_login_at', 3)->nullable();
            $table->timestamps(3);
            $table->unique('email');
            $table->unique('mobile_number');
        });
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legacy_id')->nullable()->unique();
            $table->uuid('customer_id')->nullable();
            $table->text('public_key');
            $table->string('fingerprint_hash');
            $table->text('user_agent')->nullable();
            $table->text('platform')->nullable();
            $table->text('browser')->nullable();
            $table->text('timezone')->nullable();
            $table->text('language')->nullable();
            $table->text('screen_signature')->nullable();
            $table->char('device_token_hash', 64)->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamp('last_seen_at', 3);
            $table->timestamp('created_at', 3)->nullable();
            $table->unique('device_token_hash');
            $table->index('fingerprint_hash', 'devices_fingerprint_index');
        });
        Schema::create('customer_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->char('token_hash', 64);
            $table->timestamp('expires_at', 3);
            $table->boolean('is_active')->default(true);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps(3);
            $table->unique('token_hash');
        });
        Schema::create('customer_otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('mobile_number');
            $table->text('code_hash');
            $table->string('purpose')->default('LOGIN');
            $table->string('pending_name')->nullable();
            $table->string('pending_email')->nullable();
            $table->string('ip_address')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at', 3);
            $table->timestamp('consumed_at', 3)->nullable();
            $table->timestamp('created_at', 3)->nullable();
        });
        Schema::create('merchant_branding', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->text('app_name')->nullable();
            $table->text('primary_color')->nullable();
            $table->text('header_theme')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('sidebar_notice')->nullable();
            $table->text('subbanner_text')->nullable();
            $table->text('trust_badge_text')->nullable();
            $table->text('checkout_notice')->nullable();
            $table->text('footer_guarantee')->nullable();
            $table->text('counter_instructions')->nullable();
            $table->text('card_counter_instructions')->nullable();
            $table->text('terms_url')->nullable();
            $table->text('privacy_policy_url')->nullable();
            $table->boolean('hide_trust_badge')->default(false);
            $table->boolean('hide_subbanner')->default(false);
            $table->boolean('hide_powered_by')->default(false);
            $table->boolean('hide_time_saving_banner')->default(false);
            $table->json('support_emails')->nullable();
            $table->json('support_mobiles')->nullable();
            $table->timestamps(3);
            $table->unique('merchant_id');
        });
        Schema::create('merchant_payment_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->string('selling_mode')->default('ONLINE');
            $table->boolean('cod_enabled')->default(false);
            $table->boolean('cash_enabled')->default(false);
            $table->boolean('card_enabled')->default(false);
            $table->json('enabled_payment_methods');
            $table->timestamps(3);
            $table->unique('merchant_id');
        });
        Schema::create('merchant_gmail_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('access_token')->nullable();
            $table->text('legacy_api_key')->nullable();
            $table->timestamp('token_expires_at', 3)->nullable();
            $table->timestamp('last_tested_at', 3)->nullable();
            $table->timestamp('last_successful_fetch', 3)->nullable();
            $table->timestamp('last_observation_at', 3)->nullable();
            $table->string('connected_email')->nullable();
            $table->string('last_history_id')->nullable();
            $table->string('status')->default('DISCONNECTED');
            $table->timestamps(3);
            $table->unique('merchant_id');
        });
        Schema::create('merchant_test_modes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->timestamp('enabled_at', 3)->nullable();
            $table->timestamp('expires_at', 3)->nullable();
            $table->uuid('enabled_by')->nullable();
            $table->timestamps(3);
            $table->unique('merchant_id');
        });
        Schema::create('merchant_api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->string('type');
            $table->string('mode');
            $table->string('prefix');
            $table->char('key_hash', 64);
            $table->timestamp('last_used_at', 3)->nullable();
            $table->timestamp('revoked_at', 3)->nullable();
            $table->timestamps(3);
            $table->unique('key_hash');
        });
        Schema::create('payment_receiving_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->string('upi_id');
            $table->string('payee_name');
            $table->boolean('is_merchant_enabled')->default(true);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_slot_busy')->default(false);
            $table->uuid('current_active_session_id')->nullable();
            $table->timestamps(3);
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs('CASE WHEN is_active = 1 THEN 1 ELSE NULL END');
            $table->unique(['merchant_id', 'active_marker']);
            $table->unique(['merchant_id', 'id']);
        });
        Schema::create('payment_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('receiving_account_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('order_id');
            $table->unsignedBigInteger('amount_paise');
            $table->string('currency')->default('INR');
            $table->string('method')->default('UPI');
            $table->string('status')->default('INITIATED');
            $table->unsignedInteger('queue_position')->default(0);
            $table->timestamp('slot_acquired_at', 3)->nullable();
            $table->timestamp('expires_at', 3)->nullable();
            $table->timestamp('processing_started_at', 3)->nullable();
            $table->timestamp('processing_expires_at', 3)->nullable();
            $table->timestamp('completed_at', 3)->nullable();
            $table->text('return_url')->nullable();
            $table->uuid('attributed_observation_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->unsignedTinyInteger('verification_level')->default(0);
            $table->json('verification_sources')->nullable();
            $table->string('verification_tag')->default('UNVERIFIED');
            $table->json('metadata')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps(3);
            $table->unique(['merchant_id', 'order_id']);
            $table->unique(['merchant_id', 'id']);
            $table->index(['receiving_account_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
        Schema::create('payment_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->uuid('receiving_account_id');
            $table->string('source');
            $table->unsignedBigInteger('amount_paise');
            $table->timestamp('observed_at', 3);
            $table->timestamp('ingested_at', 3);
            $table->string('sender_vpa')->nullable();
            $table->string('bank_reference_number')->nullable();
            $table->string('fingerprint_hash');
            $table->json('raw_payload');
            $table->string('attribution_status')->default('UNATTRIBUTED');
            $table->uuid('attributed_payment_id')->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->index(['receiving_account_id', 'amount_paise', 'attribution_status'], 'observations_match_index');
            $table->index('fingerprint_hash');
            $table->index('bank_reference_number');
            $table->unique(['merchant_id', 'id']);
        });
        Schema::create('android_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->string('device_name');
            $table->string('device_model')->nullable();
            $table->text('signing_key');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_heartbeat_at', 3)->nullable();
            $table->timestamp('last_observation_at', 3)->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->unique(['merchant_id', 'id']);
        });
        Schema::create('observer_pairing_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->char('token_hash', 64);
            $table->uuid('created_by');
            $table->timestamp('expires_at', 3);
            $table->timestamp('consumed_at', 3)->nullable();
            $table->uuid('consumed_by_device_id')->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->unique('token_hash');
        });
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->uuid('payment_id');
            $table->string('event_type');
            $table->longText('payload');
            $table->text('endpoint_url');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->boolean('is_successful')->default(false);
            $table->timestamp('next_retry_at', 3)->nullable();
            $table->timestamp('created_at', 3)->nullable();
        });
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->uuid('customer_id');
            $table->uuid('payment_id')->nullable();
            $table->string('subject');
            $table->string('status')->default('OPEN');
            $table->timestamps(3);
            $table->unique(['merchant_id', 'id']);
        });
        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->uuid('ticket_id');
            $table->string('sender_type');
            $table->string('sender_id');
            $table->text('message');
            $table->json('attachments')->nullable();
            $table->timestamp('created_at', 3)->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('action');
            $table->string('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at', 3)->nullable();
        });
        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->uuid('payment_session_id')->nullable();
            $table->string('event_type');
            $table->text('source')->nullable();
            $table->text('sender')->nullable();
            $table->text('device_info')->nullable();
            $table->text('normalized_message')->nullable();
            $table->text('extracted_amount')->nullable();
            $table->text('extracted_reference')->nullable();
            $table->longText('raw_message');
            $table->text('fraud_reason');
            $table->json('parser_results')->nullable();
            $table->json('verification_results')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->index('event_type');
        });
        Schema::create('merchant_customer_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->uuid('customer_id');
            $table->text('reason');
            $table->uuid('blocked_by');
            $table->timestamps(3);
            $table->unique(['merchant_id', 'customer_id']);
        });
        Schema::create('whatsapp_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->default('DISCONNECTED');
            $table->string('phone_number')->nullable();
            $table->timestamp('last_connected_at', 3)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(3);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payment_sessions ADD CONSTRAINT payment_amount_positive CHECK (amount_paise > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('legacy_settings'));
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('merchant_customer_blocks');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('observer_pairing_tokens');
        Schema::dropIfExists('android_devices');
        Schema::dropIfExists('payment_observations');
        Schema::dropIfExists('payment_sessions');
        Schema::dropIfExists('payment_receiving_accounts');
        Schema::dropIfExists('merchant_api_keys');
        Schema::dropIfExists('merchant_test_modes');
        Schema::dropIfExists('merchant_gmail_connections');
        Schema::dropIfExists('merchant_payment_settings');
        Schema::dropIfExists('merchant_branding');
        Schema::dropIfExists('customer_otp_challenges');
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('customers');
    }
};
