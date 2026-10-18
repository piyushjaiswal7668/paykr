<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Data\CustomerAuthData;
use App\Domain\Customers\Data\CustomerResult;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Management\Services\PlatformSettings;
use App\Domain\Observers\Services\WhatsAppOtp;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Domain\Shared\ValueObjects\MobileNumber;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Models\CustomerSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class CustomerAuthService
{
    public function __construct(private PasswordService $passwords, private WhatsAppOtp $whatsapp, private TenantContext $tenants) {}

    public function execute(CustomerAuthData $data): CustomerResult
    {
        try {
            return match ($data->operation) {
                'check-user' => $this->check($data),
                'send-otp' => $this->send($data),
                'verify-otp' => $this->verify($data),
                'register' => $this->register($data),
                'login' => $this->login($data),
                'me' => new CustomerResult(['customer' => $this->summary($this->customerForToken($data->token))]),
                'logout' => $this->logout($data),
                'switch-account' => new CustomerResult(['success' => true, 'token' => $data->token, 'customer' => $this->summary($this->customerForToken($data->token))], $data->token),
                default => throw new ValidationException(ErrorCode::NOT_FOUND),
            };
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(Customer::where('email', $data->email)->exists() ? ErrorCode::EMAIL_TAKEN : ErrorCode::MOBILE_TAKEN);
        }
    }

    public function customerForToken(#[\SensitiveParameter] ?string $token): Customer
    {
        if ($token === null || $token === '') {
            throw new ValidationException(ErrorCode::UNAUTHENTICATED);
        }
        $session = CustomerSession::where('token_hash', hash('sha256', $token))->where('is_active', true)->where('expires_at', '>', now())->first();
        if ($session === null) {
            throw new ValidationException(ErrorCode::CUSTOMER_SESSION_EXPIRED);
        }
        $customer = $session->customer ?? throw new ValidationException(ErrorCode::CUSTOMER_SESSION_EXPIRED);
        $this->assertAllowed($customer);

        return $customer;
    }

    public function assertAllowed(Customer $customer): void
    {
        if ($customer->status === CustomerStatus::BANNED) {
            throw new ValidationException(ErrorCode::CUSTOMER_BANNED, legacy: ['error' => 'ACCESS BLOCKED', 'status' => 'BLOCKED', 'isBanned' => true,
                'message' => 'Your account has been temporarily blocked because suspicious payment activity was detected. Our team will review the activity before access can be restored.']);
        }
    }

    /** @return array<string, mixed> */
    public function summary(Customer $customer): array
    {
        return ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'mobileNumber' => $customer->mobile_number,
            'status' => $customer->status->value, 'isPhoneVerified' => $customer->is_phone_verified, 'createdAt' => $customer->created_at, 'lastLoginAt' => $customer->last_login_at];
    }

    private function check(CustomerAuthData $data): CustomerResult
    {
        $mobile = (new MobileNumber($data->mobile))->value;
        $customer = Customer::where('mobile_number', $mobile)->first();
        $result = ['exists' => $customer !== null, 'mobileNumber' => $mobile];
        if ($customer !== null) {
            $result['maskedName'] = implode(' ', array_map(fn (string $part) => mb_substr($part, 0, 1).'*****', preg_split('/\s+/', trim($customer->name)) ?: []));
        }

        return new CustomerResult($result);
    }

    private function send(CustomerAuthData $data): CustomerResult
    {
        $mobile = (new MobileNumber($data->mobile))->value;
        $lock = Cache::lock('otp-send:'.$mobile, 15);
        $ipLock = Cache::lock('otp-send-ip:'.$data->ip, 15);
        if (! $lock->get()) {
            throw new ValidationException(ErrorCode::RATE_LIMITED);
        }
        try {
            if (! $ipLock->get()) {
                throw new ValidationException(ErrorCode::RATE_LIMITED);
            }
            $customer = Customer::where('mobile_number', $mobile)->first();
            if ($customer === null && $data->isNewUser && ($data->name === '' || ! filter_var($data->email, FILTER_VALIDATE_EMAIL))) {
                throw new ValidationException(ErrorCode::VALIDATION_FAILED);
            }
            if ($customer === null && $data->email !== '' && Customer::where('email', $data->email)->exists()) {
                throw new ValidationException(ErrorCode::EMAIL_TAKEN);
            }
            $keys = ['otp-mobile:'.$mobile => [(int) PlatformSettings::value('otp_sends_per_mobile_10_minutes', 3), 600], 'otp-ip:'.$data->ip => [(int) PlatformSettings::value('otp_sends_per_ip_hour', 10), 3600], 'otp-cooldown:'.$mobile => [1, (int) PlatformSettings::value('otp_resend_cooldown_seconds', 30)]];
            foreach ($keys as $key => [$limit, $decay]) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    throw new ValidationException(ErrorCode::RATE_LIMITED);
                }
            }
            $code = (string) random_int(100000, 999999);
            DB::transaction(function () use ($data, $mobile, $code): void {
                CustomerOtpChallenge::where('mobile_number', $mobile)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                CustomerOtpChallenge::create(['mobile_number' => $mobile, 'code_hash' => Hash::make($code), 'purpose' => 'LOGIN',
                    'pending_name' => $data->name ?: 'Customer '.substr($mobile, -4), 'pending_email' => $data->email ?: $mobile.'@customer.paykr.local',
                    'ip_address' => $data->ip, 'attempts' => 0, 'expires_at' => now()->addSeconds(300)]);
            });
            foreach ($keys as $key => [$limit, $decay]) {
                RateLimiter::hit($key, $decay);
            }
            $dispatch = $this->whatsapp->send($mobile, $code, $customer === null ? $data->name : $customer->name, PlatformSettings::value('app_name', config('paykr.defaults.app_name')));
            $result = ['success' => true, 'message' => 'OTP sent to your WhatsApp number.', 'mobileNumber' => $mobile, 'expiresInSeconds' => 300, 'dispatch' => $dispatch];
            if (app()->environment(['local', 'testing'])) {
                $result['devOtp'] = $code;
            }

            return new CustomerResult($result);
        } finally {
            $ipLock->release();
            $lock->release();
        }
    }

    private function verify(CustomerAuthData $data): CustomerResult
    {
        $mobile = (new MobileNumber($data->mobile))->value;
        // Return validation failures from the transaction so failed-attempt increments commit.
        $maxAttempts = (int) PlatformSettings::value('otp_max_attempts', 5);
        $result = DB::transaction(function () use ($mobile, $data, $maxAttempts): CustomerResult|ErrorCode {
            $challenge = CustomerOtpChallenge::where('mobile_number', $mobile)->whereNull('consumed_at')->latest()->lockForUpdate()->first();
            if ($challenge === null) {
                return ErrorCode::OTP_NOT_REQUESTED;
            }
            if ($challenge->expires_at->lessThanOrEqualTo(now())) {
                return ErrorCode::OTP_EXPIRED;
            }
            if ($challenge->attempts >= $maxAttempts) {
                return ErrorCode::OTP_ATTEMPTS_EXCEEDED;
            }
            $challenge->increment('attempts');
            if (! Hash::check($data->otp, $challenge->code_hash)) {
                return $challenge->attempts >= $maxAttempts ? ErrorCode::OTP_ATTEMPTS_EXCEEDED : ErrorCode::OTP_INVALID;
            }
            $customer = Customer::where('mobile_number', $mobile)->lockForUpdate()->first();
            if ($customer === null) {
                if (Customer::where('email', $challenge->pending_email)->exists()) {
                    return ErrorCode::EMAIL_TAKEN;
                }
                $customer = Customer::create(['name' => $challenge->pending_name, 'email' => $challenge->pending_email, 'mobile_number' => $mobile, 'status' => CustomerStatus::ACTIVE]);
            }
            $this->assertAllowed($customer);
            $challenge->update(['consumed_at' => now()]);
            $customer->update(['is_phone_verified' => true]);

            return $this->session($customer, $data, 'CUSTOMER_WHATSAPP_OTP_LOGGED_IN');
        }, 5);
        if ($result instanceof ErrorCode) {
            throw new ValidationException($result);
        }

        return $result;
    }

    private function register(CustomerAuthData $data): CustomerResult
    {
        $mobile = (new MobileNumber($data->mobile))->value;

        return DB::transaction(function () use ($data, $mobile): CustomerResult {
            $email = $data->email ?: $mobile.'@customer.paykr.local';
            if (Customer::where('email', $email)->exists()) {
                throw new ValidationException(ErrorCode::EMAIL_TAKEN);
            }
            if (Customer::where('mobile_number', $mobile)->exists()) {
                throw new ValidationException(ErrorCode::MOBILE_TAKEN);
            }
            $customer = Customer::create(['name' => $data->name, 'email' => $email, 'mobile_number' => $mobile, 'password_hash' => $this->passwords->hash($data->password), 'status' => CustomerStatus::ACTIVE, 'is_phone_verified' => false]);

            return $this->session($customer, $data, 'CUSTOMER_REGISTERED', true);
        }, 5);
    }

    private function login(CustomerAuthData $data): CustomerResult
    {
        $identifier = str_contains($data->identifier, '@') ? strtolower($data->identifier) : (new MobileNumber($data->identifier))->value;
        $customer = Customer::where(str_contains($identifier, '@') ? 'email' : 'mobile_number', $identifier)->first();
        if ($customer === null || ! $this->passwords->verify($data->password, $customer->password_hash ?? '')) {
            throw new ValidationException(ErrorCode::INVALID_CREDENTIALS);
        }
        $this->assertAllowed($customer);

        return DB::transaction(function () use ($customer, $data): CustomerResult {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->assertAllowed($customer);
            if ($this->passwords->needsRehash($customer->password_hash ?? '')) {
                $customer->update(['password_hash' => $this->passwords->hash($data->password), 'password_needs_rehash' => false]);
            }

            return $this->session($customer, $data, 'CUSTOMER_LOGGED_IN');
        });
    }

    private function session(Customer $customer, CustomerAuthData $data, string $action, bool $created = false): CustomerResult
    {
        $this->assertAllowed($customer);
        $customer->update(['last_login_at' => now()]);
        $token = 'csess_'.bin2hex(random_bytes(32));
        CustomerSession::create(['customer_id' => $customer->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30), 'is_active' => true, 'ip_address' => $data->ip, 'user_agent' => $data->userAgent]);
        $this->tenants->runUnscoped(fn () => AuditLog::create(['entity_type' => 'CUSTOMER', 'entity_id' => $customer->id, 'action' => $action, 'actor_type' => 'CUSTOMER', 'actor_id' => $customer->id]));

        return new CustomerResult(['success' => true, 'token' => $token, 'customerId' => $customer->id, 'phoneNumber' => $customer->mobile_number, 'fullName' => $customer->name, 'customer' => $this->summary($customer)], $token, $created);
    }

    private function logout(CustomerAuthData $data): CustomerResult
    {
        $this->customerForToken($data->token);
        CustomerSession::where('token_hash', hash('sha256', $data->token ?? ''))->update(['is_active' => false]);

        return new CustomerResult(['success' => true], logout: true);
    }
}
