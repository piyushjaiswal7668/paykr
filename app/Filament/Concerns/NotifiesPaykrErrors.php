<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Errors\PaykrException;
use Filament\Notifications\Notification;

final class NotifiesPaykrErrors
{
    public static function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (PaykrException $exception) {
            Notification::make()->danger()->title(__('errors.'.$exception->errorCode->value))->send();

            return null;
        }
    }
}
