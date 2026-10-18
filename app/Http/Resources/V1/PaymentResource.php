<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Payments\Data\PaymentApiResult;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PaymentResource
{
    public static function response(PaymentApiResult $result): JsonResponse
    {
        return ApiResponse::legacy(array_intersect_key($result->data, array_flip(['deviceToken', 'paymentId', 'orderId', 'amount', 'amountPaise', 'currency', 'status', 'internalStatus', 'method', 'verificationLevel', 'verificationSources', 'verificationTag', 'utr', 'completedAt', 'returnUrl', 'metadata', 'queuePosition', 'checkoutUrl', 'activeSessionDetected', 'isCod', 'placedAt', 'customer'])), $result->created ? 201 : 200);
    }
}
