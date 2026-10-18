<?php

// Feed the untouched request body and signature to this function before decoding JSON.
function verifyPaykrWebhook(string $raw, string $signature, string $secret): array
{
    if ($secret === '' || ! preg_match('/^sha256=[a-fA-F0-9]{64}$/D', $signature) || ! hash_equals(hash_hmac('sha256', $raw, $secret), strtolower(substr($signature, 7)))) {
        throw new RuntimeException('Invalid signature');
    }

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
if (PHP_SAPI === 'cli') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    try {
        verifyPaykrWebhook($input['body'], $input['signature'], $input['secret']);
        echo 'valid';
    } catch (Throwable) {
        echo 'invalid';
    }
}
