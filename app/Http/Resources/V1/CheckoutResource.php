<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domain\Checkout\Data\CheckoutResult;
use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutResource
{
    public static function response(CheckoutResult $result): Response
    {
        if ($result->svg !== null) {
            return response($result->svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store']);
        }

        return ApiResponse::legacy(array_intersect_key($result->data, array_flip(['bookingStatus', 'bookingVersion', 'whatsappUpdatesConsent', 'verificationStatus', 'canRequestReview', 'reviewId', 'reviewStatus', 'id', 'paymentId', 'orderId', 'amount', 'amountPaise', 'currency', 'status', 'internalStatus', 'method', 'verificationLevel', 'verificationSources', 'verificationTag', 'utr', 'completedAt', 'returnUrl', 'metadata', 'queuePosition', 'checkoutUrl', 'merchantName', 'branding', 'sellingMode', 'enabledMethods', 'customer', 'hasActiveSessionConflict', 'conflictingSession', 'isTerminal', 'timeRemainingSeconds', 'expiresAt', 'serverNow', 'upiUri', 'payeeVpa', 'payeeName', 'isMerchantEnabled', 'upiMode', 'upiServerIndicator', 'receivingAccountId', 'providers', 'canClickPaymentDone', 'supportEmails', 'supportMobiles', 'privacyPolicyUrl', 'deviceId', 'deviceToken', 'counterInstructions', 'isCod', 'placedAt', 'newSessionId', 'terminatedSessionIds', 'simulation', 'message', 'channel', 'tier', 'label', 'isWorking', 'reason', 'smsHealthy', 'gmailHealthy', 'warning', 'recommendedAlternative', 'isBanned', 'fraudDetected', 'customerName', 'blockedNotice'])), $result->gone ? 410 : ($result->created ? 201 : 200))->header('Cache-Control', 'no-store');
    }
}
