<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customers\Services\CustomerAuthService;
use App\Http\Requests\Api\CustomerAuthRequest;
use App\Http\Resources\V1\CustomerResource;
use Illuminate\Http\JsonResponse;

final class CustomerAuthController
{
    public function __invoke(CustomerAuthRequest $request, CustomerAuthService $service): JsonResponse
    {
        return CustomerResource::response($service->execute($request->toDto()));
    }
}
