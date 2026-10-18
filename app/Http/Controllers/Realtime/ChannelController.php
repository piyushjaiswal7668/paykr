<?php

declare(strict_types=1);

namespace App\Http\Controllers\Realtime;

use App\Domain\Realtime\Services\ChannelAuthorizer;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Http\Requests\Realtime\ChannelRequest;
use App\Http\Resources\Realtime\ChannelResource;
use Illuminate\Http\JsonResponse;

final class ChannelController
{
    public function __invoke(ChannelRequest $request, ChannelAuthorizer $authorizer): JsonResponse
    {
        if (! $authorizer->allows($request->toData())) {
            throw new ValidationException(ErrorCode::FORBIDDEN);
        }

        return ChannelResource::response($request->validated('channel_name'), $request->validated('socket_id'));
    }
}
