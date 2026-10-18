<?php

declare(strict_types=1);

namespace App\Http\Controllers\Checkout;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SdkController
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(public_path('sdk/paykr.min.js'), ['Content-Type' => 'application/javascript; charset=utf-8', 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
