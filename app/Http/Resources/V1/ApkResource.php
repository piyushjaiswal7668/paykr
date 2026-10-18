<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ApkResource
{
    public static function response(): BinaryFileResponse
    {
        $path = storage_path('app/private/releases/paykr-sms-gateway.apk');
        if (! is_file($path)) {
            throw new ValidationException(ErrorCode::NOT_FOUND);
        }

        return response()->download($path, 'paykr-sms-gateway.apk', ['Content-Type' => 'application/vnd.android.package-archive', 'X-Content-Type-Options' => 'nosniff']);
    }
}
