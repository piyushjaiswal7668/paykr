<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Checkout\Services\CheckoutService;
use App\Http\Requests\Api\CheckoutRequest;
use App\Http\Resources\V1\CheckoutResource;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutController
{
    public function __invoke(CheckoutRequest $request, CheckoutService $service): Response
    {
        return CheckoutResource::response($service->execute($request->toDto()));
    }
}
