<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Observers\Data\GmailResult;
use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

final class GmailResource
{
    public static function response(GmailResult $result, bool $page = false): Response
    {
        if ($result->redirect !== null) {
            return redirect()->away($result->redirect)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        if ($page) {
            return response()->view('merchant.gmail-result', ['status' => $result->status], 200)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return ApiResponse::success(['status' => $result->status, 'connectedEmail' => $result->email])->header('Cache-Control', 'no-store');
    }
}
