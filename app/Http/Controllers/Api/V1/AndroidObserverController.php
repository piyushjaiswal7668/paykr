<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Observers\Services\AndroidObserver;
use App\Http\Requests\Api\ObserverRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AndroidObserverController
{
    public function __invoke(ObserverRequest $request, AndroidObserver $observer): JsonResponse
    {
        return ApiResponse::legacy($observer->execute($request->toDto())->data)->header('Cache-Control', 'no-store');
    }
}
