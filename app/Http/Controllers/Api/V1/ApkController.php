<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\ApkResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ApkController
{
    public function __invoke(): BinaryFileResponse
    {
        return ApkResource::response();
    }
}
