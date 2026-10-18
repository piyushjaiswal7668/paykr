<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Services\PaymentApiService;
use App\Http\Requests\Api\PaymentApiRequest;
use App\Http\Resources\V1\PaymentResource;
use Illuminate\Http\JsonResponse;

final class PaymentApiController
{
    public function __invoke(PaymentApiRequest $request, PaymentApiService $service): JsonResponse
    {
        return PaymentResource::response($service->execute($request->toDto()));
    }
}
