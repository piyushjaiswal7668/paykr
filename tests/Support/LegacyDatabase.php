<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use PDO;

final class LegacyDatabase
{
    public string $path;

    public PDO $db;

    /** @var array<string, string> */
    public array $ids;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'paykr-source-');
        $this->db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(file_get_contents(__DIR__.'/../Fixtures/legacy-schema.sql'));
        foreach (['merchant', 'customer', 'account', 'payment', 'observation', 'ticket', 'android'] as $name) {
            $this->ids[$name] = (string) Str::uuid();
        }
        $this->ids['device'] = 'dev_'.str_repeat('a', 36);
        $this->insert('merchants', ['id' => $this->ids['merchant'], 'business_name' => 'Legacy Store', 'slug' => 'legacy-store', 'contact_email' => 'store@example.test', 'contact_phone' => '9876543210', 'webhook_secret' => 'legacy-webhook-secret', 'branding' => json_encode([
            'appName' => 'Legacy Pay', 'primaryColor' => '#123456', 'sellingMode' => 'OFFLINE', 'cashEnabled' => true, 'cardEnabled' => true, 'codEnabled' => false,
            'supportEmails' => ['help@example.test'], 'supportMobiles' => ['9876543210'],
            'gmailConfig' => ['clientId' => 'client', 'clientSecret' => 'gmail-secret', 'refreshToken' => 'refresh', 'accessToken' => 'access', 'apiKey' => 'api-key', 'lastTestedAt' => '2026-10-17T12:34:56.789Z'],
            'testModeEnabledAt' => 1792238400000, 'testModeUntil' => 1792239000000, 'cloudflareTunnelCommand' => 'legacy-unused-command',
        ])]);
        $this->insert('customers', ['id' => $this->ids['customer'], 'name' => 'Example Customer', 'email' => 'customer@example.test', 'mobile_number' => '9876543210', 'password_hash' => '100000:salt:legacyhash', 'otp_code' => '123456', 'otp_expires_at' => '2026-10-18T12:00:00.000Z']);
        $this->insert('devices', ['id' => $this->ids['device'], 'customer_id' => $this->ids['customer'], 'public_key' => 'public-key', 'fingerprint_hash' => 'fingerprint', 'device_token' => 'device-secret']);
        $this->insert('customer_sessions', ['id' => (string) Str::uuid(), 'customer_id' => $this->ids['customer'], 'token' => 'csess_original', 'expires_at' => '2026-11-18T12:00:00.000Z']);
        $this->insert('payment_receiving_accounts', ['id' => $this->ids['account'], 'merchant_id' => $this->ids['merchant'], 'upi_id' => 'merchant@upi', 'payee_name' => 'Legacy Store', 'current_active_session_id' => $this->ids['payment']]);
        $this->insert('payment_sessions', ['id' => $this->ids['payment'], 'merchant_id' => $this->ids['merchant'], 'customer_id' => $this->ids['customer'], 'receiving_account_id' => $this->ids['account'], 'device_id' => $this->ids['device'], 'order_id' => 'ORDER-1', 'amount_paise' => 12345, 'status' => 'SUCCESS', 'attributed_observation_id' => $this->ids['observation'], 'metadata' => '{"sku":"test"}', 'created_at' => '2026-10-17T12:34:56.789Z']);
        $this->insert('payment_observations', ['id' => $this->ids['observation'], 'receiving_account_id' => $this->ids['account'], 'source' => 'SMS_GATEWAY', 'amount_paise' => 12345, 'observed_at' => '2026-10-17T12:34:57.789Z', 'fingerprint_hash' => 'obs-fingerprint', 'attribution_status' => 'ATTRIBUTED', 'attributed_payment_id' => $this->ids['payment']]);
        $this->insert('android_devices', ['id' => $this->ids['android'], 'merchant_id' => $this->ids['merchant'], 'device_name' => 'Observer', 'signing_key_hash' => 'raw-signing-key']);
        $this->insert('webhook_deliveries', ['id' => (string) Str::uuid(), 'merchant_id' => $this->ids['merchant'], 'payment_id' => $this->ids['payment'], 'event_type' => 'SUCCESS', 'payload' => '{ "event": "SUCCESS", "amount": "123.45" }', 'endpoint_url' => 'https://store.example/webhook']);
        $this->insert('support_tickets', ['id' => $this->ids['ticket'], 'merchant_id' => $this->ids['merchant'], 'customer_id' => $this->ids['customer'], 'payment_id' => $this->ids['payment'], 'subject' => 'Legacy ticket']);
        $this->insert('ticket_messages', ['id' => (string) Str::uuid(), 'ticket_id' => $this->ids['ticket'], 'sender_type' => 'CUSTOMER', 'sender_id' => $this->ids['customer'], 'message' => 'Test support message']);
        $this->insert('audit_logs', ['id' => (string) Str::uuid(), 'merchant_id' => $this->ids['merchant'], 'entity_type' => 'payment', 'entity_id' => $this->ids['payment'], 'action' => 'PAYMENT_CREATED', 'actor_type' => 'ADMIN', 'actor_id' => 'ADMIN_USER']);
        $this->insert('security_events', ['id' => (string) Str::uuid(), 'merchant_id' => $this->ids['merchant'], 'customer_id' => $this->ids['customer'], 'payment_session_id' => $this->ids['payment'], 'event_type' => 'FRAUD', 'raw_message' => 'unaltered evidence', 'fraud_reason' => 'Test evidence']);
    }

    /** @param array<string, mixed> $values */
    public function insert(string $table, array $values): void
    {
        $columns = $this->db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (in_array('updated_at', $columns, true)) {
            $values['updated_at'] ??= '2026-10-17T12:34:56.222Z';
        }
        $this->db->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')')->execute(array_values($values));
    }

    public function __destruct()
    {
        unlink($this->path);
    }
}
