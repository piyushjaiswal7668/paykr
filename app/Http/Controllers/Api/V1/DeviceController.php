<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\Services\DeviceService;
use App\Http\Requests\Api\DeviceRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class DeviceController
{
    public function __invoke(DeviceRequest $request, DeviceService $service): JsonResponse
    {
        return ApiResponse::legacy($service->execute($request->toDto())->data);
    }
}
