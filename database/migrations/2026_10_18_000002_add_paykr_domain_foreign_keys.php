<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
        Schema::table('customer_sessions', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
        Schema::table('merchant_test_modes', function (Blueprint $table): void {
            $table->foreign('enabled_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('payment_receiving_accounts', function (Blueprint $table): void {
            $table->foreign(['merchant_id', 'current_active_session_id'], 'payment_receiv_current_active_ses_tenant_fk')->references(['merchant_id', 'id'])->on('payment_sessions')->restrictOnDelete();
        });
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign(['merchant_id', 'receiving_account_id'], 'payment_sessio_receiving_account__tenant_fk')->references(['merchant_id', 'id'])->on('payment_receiving_accounts')->restrictOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->restrictOnDelete();
            $table->foreign(['merchant_id', 'attributed_observation_id'], 'payment_sessio_attributed_observa_tenant_fk')->references(['merchant_id', 'id'])->on('payment_observations')->restrictOnDelete();
        });
        Schema::table('payment_observations', function (Blueprint $table): void {
            $table->foreign(['merchant_id', 'receiving_account_id'], 'payment_observ_receiving_account__tenant_fk')->references(['merchant_id', 'id'])->on('payment_receiving_accounts')->restrictOnDelete();
            $table->foreign(['merchant_id', 'attributed_payment_id'], 'payment_observ_attributed_payment_tenant_fk')->references(['merchant_id', 'id'])->on('payment_sessions')->restrictOnDelete();
        });
        Schema::table('observer_pairing_tokens', function (Blueprint $table): void {
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['merchant_id', 'consumed_by_device_id'], 'observer_pairi_consumed_by_device_tenant_fk')->references(['merchant_id', 'id'])->on('android_devices')->restrictOnDelete();
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->foreign(['merchant_id', 'payment_id'], 'webhook_delive_payment_id_tenant_fk')->references(['merchant_id', 'id'])->on('payment_sessions')->restrictOnDelete();
        });
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign(['merchant_id', 'payment_id'], 'support_ticket_payment_id_tenant_fk')->references(['merchant_id', 'id'])->on('payment_sessions')->restrictOnDelete();
        });
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->foreign(['merchant_id', 'ticket_id'], 'ticket_message_ticket_id_tenant_fk')->references(['merchant_id', 'id'])->on('support_tickets')->restrictOnDelete();
        });
        Schema::table('security_events', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign(['merchant_id', 'payment_session_id'], 'security_event_payment_session_id_tenant_fk')->references(['merchant_id', 'id'])->on('payment_sessions')->restrictOnDelete();
        });
        Schema::table('merchant_customer_blocks', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('blocked_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_customer_blocks', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['blocked_by']);
        });
        Schema::table('security_events', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropForeign('security_event_payment_session_id_tenant_fk');
        });
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->dropForeign('ticket_message_ticket_id_tenant_fk');
        });
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropForeign('support_ticket_payment_id_tenant_fk');
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropForeign('webhook_delive_payment_id_tenant_fk');
        });
        Schema::table('observer_pairing_tokens', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign('observer_pairi_consumed_by_device_tenant_fk');
        });
        Schema::table('payment_observations', function (Blueprint $table): void {
            $table->dropForeign('payment_observ_receiving_account__tenant_fk');
            $table->dropForeign('payment_observ_attributed_payment_tenant_fk');
        });
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropForeign('payment_sessio_receiving_account__tenant_fk');
            $table->dropForeign(['device_id']);
            $table->dropForeign('payment_sessio_attributed_observa_tenant_fk');
        });
        Schema::table('payment_receiving_accounts', function (Blueprint $table): void {
            $table->dropForeign('payment_receiv_current_active_ses_tenant_fk');
        });
        Schema::table('merchant_test_modes', function (Blueprint $table): void {
            $table->dropForeign(['enabled_by']);
        });
        Schema::table('customer_sessions', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
        });
    }
};
