<?php

declare(strict_types=1);

namespace App\Http\Controllers\Checkout;

use App\Domain\Checkout\Services\CheckoutPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PageController
{
    public function __invoke(Request $request, string $id, CheckoutPage $page): Response
    {
        $bootstrap = $page->bootstrap($id, $request->header('Referer'));

        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port', 443);
        $socket = preg_match('/^[a-zA-Z0-9.-]+$/D', $host) ? ((config('broadcasting.connections.reverb.options.useTLS') ? 'wss://' : 'ws://').$host.':'.$port) : '';
        $policy = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self'; connect-src 'self' {$socket}; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self' ".implode(' ', $bootstrap['allowedOrigins']);

        return response()->view('checkout.app', ['bootstrap' => $bootstrap])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'strict-origin-when-cross-origin')->header('Content-Security-Policy', $policy);
    }
}
