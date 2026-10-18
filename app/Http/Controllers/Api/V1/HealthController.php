<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Platform\Services\ClockStatus;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(ClockStatus $clock): JsonResponse
    {
        return ApiResponse::legacy(['status' => 'UP', 'clock' => $clock->read()]);
    }
}
