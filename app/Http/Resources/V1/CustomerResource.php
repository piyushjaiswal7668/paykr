<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Customers\Data\CustomerResult;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class CustomerResource
{
    public static function response(CustomerResult $result): JsonResponse
    {
        $data = array_intersect_key($result->data, array_flip(['success', 'message', 'exists', 'mobileNumber', 'maskedName', 'expiresInSeconds', 'dispatch', 'devOtp', 'token', 'customerId', 'phoneNumber', 'fullName', 'customer']));
        if (isset($data['customer'])) {
            $data['customer'] = array_intersect_key($data['customer'], array_flip(['id', 'name', 'email', 'mobileNumber', 'status', 'isPhoneVerified', 'createdAt', 'lastLoginAt']));
        }
        $response = ApiResponse::legacy($data, $result->created ? 201 : 200);
        if ($result->token !== null || $result->logout) {
            $response->withCookie(cookie('paykr_session', $result->token ?? '', $result->logout ? -1 : 43200, '/', null, ! app()->environment(['local', 'testing']), true, false, 'lax'));
        }

        return $response;
    }
}
