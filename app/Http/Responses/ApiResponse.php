<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /** @param array<string, mixed> $meta */
    public static function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => (object) $meta], $status);
    }

    /** @param array<string, mixed> $data */
    public static function legacy(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }
}
