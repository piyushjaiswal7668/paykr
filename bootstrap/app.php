<?php

declare(strict_types=1);

use App\Domain\Shared\Errors\PaykrException;
use App\Http\Middleware\RequestId;
use App\Http\Responses\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->redirectGuestsTo('/merchant/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PaykrException $e, Request $request) {
            return $request->is('merchant/gmail/*') && ! $request->expectsJson() ? response()->view('merchant.gmail-result', ['status' => 'ERROR'], $e->errorCode->httpStatus())->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer') : null;
        });
        $exceptions->render(fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)($e, $request));
    })->create();
