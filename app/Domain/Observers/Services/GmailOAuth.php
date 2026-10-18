<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observers\Data\GmailRequest;
use App\Domain\Observers\Data\GmailResult;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\MerchantGmailConnection;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;

final class GmailOAuth
{
    public function __construct(private TenantContext $tenants) {}

    public function execute(GmailRequest $data): GmailResult
    {
        try {
            if ($data->operation === 'callback') {
                return $this->callback($data);
            }
            $this->authorize($data->merchantId, $data->userId);

            return $this->tenants->run($data->merchantId, fn () => match ($data->operation) {
                'connect' => $this->connect($data),
                'test' => $this->test(),
                'disconnect' => $this->disconnect(),
                default => throw new ValidationException(ErrorCode::NOT_FOUND),
            });
        } catch (ConnectionException) {
            throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
        }
    }

    private function authorize(string $merchantId, string $userId): void
    {
        $user = User::find($userId);
        if ($user === null || (! $user->is_platform_admin && ! $user->merchants()->whereKey($merchantId)->wherePivotIn('role', ['owner', 'manager', 'developer'])->exists())) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }
        if (! Merchant::whereKey($merchantId)->where('status', 'ACTIVE')->exists()) {
            throw new ValidationException(ErrorCode::MERCHANT_NOT_FOUND);
        }
    }

    private function connect(GmailRequest $data): GmailResult
    {
        $connection = MerchantGmailConnection::first();
        $client = $connection?->client_id ?: config('paykr.gmail.client_id');
        $secret = $connection?->client_secret ?: config('paykr.gmail.client_secret');
        if (! is_string($client) || $client === '' || ! is_string($secret) || $secret === '') {
            throw new ValidationException(ErrorCode::GMAIL_NOT_CONFIGURED);
        }
        $nonce = bin2hex(random_bytes(32));
        $state = Crypt::encryptString(json_encode(['merchantId' => $data->merchantId, 'userId' => $data->userId, 'nonce' => $nonce], JSON_THROW_ON_ERROR));
        DB::table('gmail_oauth_states')->insert(['nonce_hash' => hash('sha256', $nonce), 'merchant_id' => $data->merchantId, 'user_id' => $data->userId, 'expires_at' => now()->addMinutes(10)]);
        $query = http_build_query(['client_id' => $client, 'redirect_uri' => config('paykr.gmail.redirect_uri'), 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/userinfo.email', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state], '', '&', PHP_QUERY_RFC3986);

        return new GmailResult('REDIRECT', 'https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    private function callback(GmailRequest $data): GmailResult
    {
        try {
            $state = json_decode(Crypt::decryptString($data->state), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
        }
        if (! is_array($state) || ! is_string($state['merchantId'] ?? null) || ! is_string($state['nonce'] ?? null) || ($state['userId'] ?? null) !== $data->userId) {
            throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
        }
        $this->authorize($state['merchantId'], $data->userId);

        return $this->tenants->run($state['merchantId'], function () use ($state, $data): GmailResult {
            $consumed = DB::table('gmail_oauth_states')->where('nonce_hash', hash('sha256', $state['nonce']))->where('user_id', $data->userId)->where('merchant_id', $state['merchantId'])->where('expires_at', '>', now())->delete();
            if ($consumed !== 1) {
                throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
            }
            if ($data->denied) {
                return new GmailResult('DENIED');
            }

            return $this->locked(function () use ($data, $state): GmailResult {
                $connection = MerchantGmailConnection::first();
                $client = $connection?->client_id ?: config('paykr.gmail.client_id');
                $secret = $connection?->client_secret ?: config('paykr.gmail.client_secret');
                $response = Http::asForm()->connectTimeout(2)->timeout(5)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', ['client_id' => $client, 'client_secret' => $secret, 'code' => $data->code, 'grant_type' => 'authorization_code', 'redirect_uri' => config('paykr.gmail.redirect_uri')]);
                $access = $response->json('access_token');
                $refresh = $response->json('refresh_token');
                $expires = $response->json('expires_in');
                if (! $response->successful() || ! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '' || ! is_numeric($expires)) {
                    throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
                }
                $email = $this->profile($access);
                DB::transaction(function () use ($connection, $state, $client, $secret, $access, $refresh, $expires, $email): void {
                    if ($connection !== null && $connection->connected_email !== $email) {
                        DB::table('gmail_processed_messages')->where('merchant_id', $state['merchantId'])->delete();
                    }
                    MerchantGmailConnection::updateOrCreate(['merchant_id' => $state['merchantId']], ['client_id' => $client, 'client_secret' => $secret, 'access_token' => $access, 'refresh_token' => $refresh, 'token_expires_at' => now()->addSeconds((int) $expires), 'connected_email' => $email, 'last_tested_at' => now(), 'last_successful_fetch' => null, 'last_history_id' => null, 'status' => 'CONNECTED']);
                });

                return new GmailResult('CONNECTED', email: $email);
            });
        });
    }

    private function test(): GmailResult
    {
        return $this->locked(function (): GmailResult {
            $connection = MerchantGmailConnection::first() ?? throw new ValidationException(ErrorCode::GMAIL_NOT_CONFIGURED);
            $token = app(GmailPoller::class)->accessToken($connection);
            $email = $this->profile($token);
            $connection->update(['connected_email' => $email, 'last_tested_at' => now(), 'status' => 'CONNECTED']);

            return new GmailResult('CONNECTED', email: $email);
        });
    }

    private function disconnect(): GmailResult
    {
        return $this->locked(function (): GmailResult {
            $connection = MerchantGmailConnection::first();
            if ($connection === null) {
                return new GmailResult('DISCONNECTED');
            }
            $token = $connection->refresh_token ?: $connection->access_token;
            if ($token) {
                $response = Http::asForm()->connectTimeout(2)->timeout(5)->withoutRedirecting()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
                if (! $response->successful() && ! ($response->status() === 400 && $response->json('error') === 'invalid_token')) {
                    throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
                }
            }
            $connection->update(['refresh_token' => null, 'access_token' => null, 'token_expires_at' => null, 'connected_email' => null, 'last_successful_fetch' => null, 'status' => 'DISCONNECTED']);

            return new GmailResult('DISCONNECTED');
        });
    }

    private function profile(#[\SensitiveParameter] string $token): string
    {
        $response = Http::withToken($token)->connectTimeout(2)->timeout(5)->withoutRedirecting()->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');
        $email = $response->json('emailAddress');
        if (! $response->successful() || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
        }

        return $email;
    }

    /** @param callable(): GmailResult $callback */
    private function locked(callable $callback): GmailResult
    {
        $lock = Cache::lock('paykr:gmail:poll:'.$this->tenants->merchantId(), 90);
        if (! $lock->get()) {
            throw new ValidationException(ErrorCode::RATE_LIMITED);
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
