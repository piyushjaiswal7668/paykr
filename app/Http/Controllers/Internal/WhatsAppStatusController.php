<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Observers\Services\WhatsAppStatusReceiver;
use App\Http\Requests\Api\WhatsAppStatusRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class WhatsAppStatusController
{
    public function __invoke(WhatsAppStatusRequest $request, WhatsAppStatusReceiver $receiver): JsonResponse
    {
        $receiver->receive($request->toDto());

        return ApiResponse::legacy(['success' => true])->header('Cache-Control', 'no-store');
    }
}
