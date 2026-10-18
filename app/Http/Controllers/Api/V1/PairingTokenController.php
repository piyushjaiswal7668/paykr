<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Observers\Services\AndroidObserver;
use App\Http\Requests\Api\PairingTokenRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PairingTokenController
{
    public function __invoke(PairingTokenRequest $request, AndroidObserver $observer): JsonResponse
    {
        return ApiResponse::success($observer->issue($request->merchantId(), $request->actorId())->data)->header('Cache-Control', 'no-store');
    }
}
