<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\PaykrException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $exception instanceof PaykrException && ! $request->is('api', 'api/*', 'internal', 'internal/*') && ! $request->expectsJson()) {
            return null;
        }
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $code = match (true) {
            $exception instanceof PaykrException => $exception->errorCode,
            $exception instanceof ValidationException => ErrorCode::VALIDATION_FAILED,
            $exception instanceof AuthenticationException => ErrorCode::UNAUTHENTICATED,
            $exception instanceof AuthorizationException => ErrorCode::FORBIDDEN,
            default => match ($status) {
                401 => ErrorCode::UNAUTHENTICATED,
                403 => ErrorCode::FORBIDDEN,
                404 => ErrorCode::NOT_FOUND,
                419 => ErrorCode::CSRF_TOKEN_MISMATCH,
                429 => ErrorCode::RATE_LIMITED,
                503 => ErrorCode::SERVICE_UNAVAILABLE,
                default => ErrorCode::INTERNAL_ERROR,
            },
        };
        $message = __('errors.'.$code->value);
        $id = $request->attributes->get('request_id') ?? (string) Str::ulid();
        $error = ['code' => $code->value, 'message' => $message, 'details' => (object) ($exception instanceof PaykrException ? $exception->details : [])];
        if ($exception instanceof ValidationException) {
            $error['fields'] = $exception->errors();
        }
        $legacy = $request->is('api/v1/*') && ! $request->is('api/v1/merchant', 'api/v1/merchant/*');
        $body = $legacy
            ? array_merge($error, ['error' => $message], $exception instanceof PaykrException ? $exception->legacy() : [], ['success' => false, 'code' => $code->value, 'request_id' => $id])
            : ['success' => false, 'error' => $error, 'request_id' => $id];
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $responseStatus = $request->isMethod('POST') && $request->is('api/v1/payments') && ($code === ErrorCode::INVALID_AMOUNT || ($exception instanceof ValidationException && array_key_exists('amount', $exception->errors()))) ? 400 : $code->httpStatus();

        return response()->json($body, $responseStatus, array_merge($headers, ['X-Request-Id' => $id]));
    }
}
