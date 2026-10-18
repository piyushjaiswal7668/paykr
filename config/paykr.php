<?php

declare(strict_types=1);

return [
    'allow_legacy_merchant_header' => (bool) env('PAYKR_ALLOW_LEGACY_MERCHANT_HEADER', false),
    'internal_token' => env('PAYKR_INTERNAL_TOKEN'),
    'admin' => ['email' => env('PAYKR_ADMIN_EMAIL'), 'password' => env('PAYKR_ADMIN_PASSWORD')],
    'payments' => [
        'active_window_seconds' => (int) env('PAYMENT_ACTIVE_WINDOW_SECONDS', 300),
        'processing_window_seconds' => (int) env('PAYMENT_PROCESSING_WINDOW_SECONDS', 30),
        'late_observation_window_minutes' => (int) env('LATE_OBSERVATION_WINDOW_MINUTES', 30),
        'grace_period_ms' => (int) env('PAYMENT_GRACE_PERIOD_MS', 5000),
        'verification_upgrade_seconds' => (int) env('PAYMENT_VERIFICATION_UPGRADE_SECONDS', 60),
    ],
    'webhooks' => [
        'max_retries' => (int) env('WEBHOOK_MAX_RETRIES', 5),
        'backoff_seconds' => [5, 30, 120, 600, 3600],
        'initial_backoff_ms' => (int) env('WEBHOOK_INITIAL_BACKOFF_MS', 5000),
        'retry_interval_ms' => (int) env('WEBHOOK_RETRY_INTERVAL_MS', 5000),
    ],
    'sidecars' => [
        'whatsapp_enabled' => (bool) env('WHATSAPP_SIDECAR_ENABLED', false),
        'whatsapp_url' => env('WHATSAPP_SIDECAR_URL', 'http://127.0.0.1:6101'),
        'messages_url' => env('MESSAGES_SIDECAR_URL', 'http://127.0.0.1:6102'),
        'messages_enabled' => (bool) env('MESSAGES_SIDECAR_ENABLED', false),
    ],
    'gmail' => [
        'client_id' => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'redirect_uri' => env('GMAIL_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost:8000'), '/').'/merchant/gmail/callback'),
        'poll_interval_ms' => (int) env('GMAIL_POLL_INTERVAL_MS', 5000),
        'idle_poll_seconds' => (int) env('GMAIL_IDLE_POLL_SECONDS', 60),
    ],
    'android' => [
        'dev_enrollment_secret' => in_array(env('APP_ENV'), ['local', 'testing'], true) ? env('ANDROID_DEV_ENROLLMENT_SECRET') : null,
        'heartbeat_timeout_seconds' => (int) env('ANDROID_HEARTBEAT_TIMEOUT_SECONDS', 60),
        'signature_drift_seconds' => 60,
        'pairing_ttl_seconds' => 600,
    ],
    'otp' => ['ttl_seconds' => 300, 'max_attempts' => 5, 'resend_cooldown_seconds' => 30],
    'rate_limits' => [
        'login_attempts' => (int) env('PAYKR_LOGIN_ATTEMPTS', 5),
        'login_decay_seconds' => (int) env('PAYKR_LOGIN_DECAY_SECONDS', 900),
        'otp_sends_per_mobile_10_minutes' => 3,
        'otp_sends_per_ip_hour' => 10,
        'check_user_per_minute' => (int) env('PAYKR_CHECK_USER_PER_MINUTE', 30),
        'verify_otp_per_minute' => (int) env('PAYKR_VERIFY_OTP_PER_MINUTE', 10),
        'devices_register_per_minute' => (int) env('PAYKR_DEVICES_REGISTER_PER_MINUTE', 30),
        'payments_per_minute' => (int) env('PAYKR_PAYMENTS_PER_MINUTE', 60),
        'trigger_hook_per_minute' => (int) env('PAYKR_TRIGGER_HOOK_PER_MINUTE', 10),
    ],
    'defaults' => [
        'app_name' => env('DEFAULT_APP_NAME', 'Paykr'),
        'primary_color' => env('DEFAULT_PRIMARY_COLOR', '#2563eb'),
        'support_email' => env('DEFAULT_SUPPORT_EMAIL'),
    ],
    'demo' => ['webhook_url' => env('COIN_STORE_WEBHOOK_URL', 'http://localhost:5000/api/webhook'), 'webhook_secret' => env('COIN_STORE_WEBHOOK_SECRET')],
];
