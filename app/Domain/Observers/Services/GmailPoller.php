<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Verification\Data\VerificationData;
use App\Domain\Verification\Services\VerificationPipeline;
use App\Models\MerchantGmailConnection;
use App\Models\PaymentReceivingAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class GmailPoller
{
    public function __construct(private VerificationPipeline $pipeline) {}

    public function poll(): void
    {
        $connection = MerchantGmailConnection::first();
        if ($connection === null || trim($connection->refresh_token ?? '') === '') {
            return;
        }
        $lock = Cache::lock('paykr:gmail:poll:'.$connection->merchant_id, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            if (! Cache::add('paykr:gmail:last-poll:'.$connection->merchant_id, true, 2)) {
                return;
            }
            $connection->refresh();
            $token = $this->accessToken($connection);
            $request = Http::withToken($token)->connectTimeout(2)->timeout(5)->withoutRedirecting();
            $list = $request->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', ['q' => 'subject:(credit OR credited OR received OR upi OR deposit OR alert)', 'maxResults' => 10]);
            if (! $list->successful()) {
                throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
            }
            $messages = $list->json('messages', []);
            if (! is_array($messages)) {
                throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
            }
            $account = PaymentReceivingAccount::where('is_active', true)->first();
            if ($account !== null) {
                foreach (array_slice($messages, 0, 10) as $message) {
                    if (is_array($message) && is_string($message['id'] ?? null) && preg_match('/^[a-zA-Z0-9_-]+$/D', $message['id'])) {
                        $this->message($request, $connection->merchant_id, $account->id, $message['id']);
                    }
                }
            }
            $connection->update(['last_successful_fetch' => now(), 'status' => 'CONNECTED']);
        } catch (ConnectionException) {
            throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
        } finally {
            $lock->release();
        }
    }

    public function accessToken(MerchantGmailConnection $connection): string
    {
        if (! $connection->refresh_token) {
            throw new ValidationException(ErrorCode::GMAIL_NOT_CONFIGURED);
        }
        if ($connection->token_expires_at === null || $connection->token_expires_at->lessThanOrEqualTo(now()->addSeconds(120)) || ! $connection->access_token) {
            $response = Http::asForm()->connectTimeout(2)->timeout(5)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $connection->client_id, 'client_secret' => $connection->client_secret,
                'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token')) || ! is_numeric($response->json('expires_in'))) {
                throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
            }
            $connection->update(['access_token' => $response->json('access_token'), 'token_expires_at' => now()->addSeconds((int) $response->json('expires_in'))]);
        }
        $token = $connection->access_token;
        if (! is_string($token) || $token === '') {
            throw new ValidationException(ErrorCode::GMAIL_OAUTH_FAILED);
        }

        return $token;
    }

    private function message(PendingRequest $request, string $merchantId, string $accountId, string $id): void
    {
        if (DB::table('gmail_processed_messages')->where('merchant_id', $merchantId)->where('message_id', $id)->exists()) {
            return;
        }
        $response = $request->get('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.$id, ['format' => 'full']);
        $payload = $response->json('payload');
        $date = $response->json('internalDate');
        if (! $response->successful() || ! is_array($payload) || ! is_numeric($date)) {
            throw new ValidationException(ErrorCode::SERVICE_UNAVAILABLE);
        }
        $headers = [];
        foreach ($payload['headers'] ?? [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && is_string($header['value'] ?? null)) {
                $headers[strtolower($header['name'])] = $header['value'];
            }
        }
        $text = ($headers['subject'] ?? '')."\n".($this->body($payload) ?: (string) $response->json('snippet', ''));
        DB::transaction(function () use ($merchantId, $accountId, $id, $headers, $text, $date): void {
            // Receipt and verification commit together; a crash before commit leaves the message retryable.
            if (DB::table('gmail_processed_messages')->where('merchant_id', $merchantId)->where('message_id', $id)->exists()) {
                return;
            }
            $this->pipeline->process(new VerificationData('GMAIL', $text, $accountId, sender: $headers['from'] ?? '', receivedAt: CarbonImmutable::createFromTimestampMs($date), messageId: $id));
            DB::table('gmail_processed_messages')->insert(['merchant_id' => $merchantId, 'message_id' => $id, 'processed_at' => now()->format('Y-m-d H:i:s.v')]);
        }, 5);
    }

    /** @param array<string, mixed> $part */
    private function body(array $part): string
    {
        $plain = '';
        $html = '';
        $other = '';
        $parts = [$part];
        while ($parts !== []) {
            $node = array_shift($parts);
            if (! is_array($node)) {
                continue;
            }
            $data = $node['body']['data'] ?? null;
            $text = is_string($data) ? base64_decode(strtr($data, '-_', '+/'), true) : false;
            if ($text !== false) {
                if (($node['mimeType'] ?? '') === 'text/plain') {
                    $plain .= $text;
                } elseif (($node['mimeType'] ?? '') === 'text/html') {
                    $html .= html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                } else {
                    $other .= $text;
                }
            }
            foreach ($node['parts'] ?? [] as $child) {
                $parts[] = $child;
            }
        }

        return $plain ?: ($html ?: $other);
    }
}
