<?php

declare(strict_types=1);

namespace App\Http\Resources\Realtime;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Broadcast;

final class ChannelResource
{
    public static function response(string $channel, string $socket): JsonResponse
    {
        /** @var PusherBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('reverb');
        $signed = json_decode($broadcaster->getPusher()->authorizeChannel($channel, $socket), true, flags: JSON_THROW_ON_ERROR);

        return response()->json(['auth' => $signed['auth']])->header('Cache-Control', 'no-store');
    }
}
