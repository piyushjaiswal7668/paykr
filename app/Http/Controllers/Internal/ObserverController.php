<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Observers\Services\InternalObserver;
use App\Http\Requests\Api\ObserverRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ObserverController
{
    public function __invoke(ObserverRequest $request, InternalObserver $observer): JsonResponse
    {
        return ApiResponse::legacy($observer->execute($request->toDto())->data)->header('Cache-Control', 'no-store');
    }
}
