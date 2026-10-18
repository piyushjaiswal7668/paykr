<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;

enum PaymentStatus: string
{
    case INITIATED = 'INITIATED';
    case QUEUED = 'QUEUED';
    case ACTIVE = 'ACTIVE';
    case PROCESSING = 'PROCESSING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCESS, self::FAILED, self::EXPIRED, self::CANCELLED], true);
    }

    public function canTransitionTo(self $target, PaymentMethod $method = PaymentMethod::UPI): bool
    {
        return match ($this) {
            self::INITIATED => in_array($target, [self::ACTIVE, self::CANCELLED, self::EXPIRED], true) || ($target === self::QUEUED && $method === PaymentMethod::UPI),
            self::QUEUED => in_array($target, [self::ACTIVE, self::CANCELLED, self::EXPIRED], true),
            self::ACTIVE => in_array($target, [self::PROCESSING, self::SUCCESS, self::EXPIRED, self::CANCELLED], true) || ($target === self::FAILED && in_array($method, [PaymentMethod::CASH_COUNTER, PaymentMethod::CARD_COUNTER], true)),
            self::PROCESSING => in_array($target, [self::SUCCESS, self::FAILED, self::CANCELLED, self::EXPIRED], true),
            default => false,
        };
    }

    public function publicOutcome(): string
    {
        if (! $this->isTerminal()) {
            throw new ValidationException(ErrorCode::INVALID_STATE_TRANSITION);
        }

        return $this->value;
    }
}
