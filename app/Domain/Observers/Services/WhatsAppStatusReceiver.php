<?php

declare(strict_types=1);

namespace App\Domain\Observers\Services;

use App\Domain\Observers\Data\WhatsAppState;
use App\Domain\Observers\Enums\WhatsAppStatus;
use App\Models\WhatsappSession;

final class WhatsAppStatusReceiver
{
    public function receive(WhatsAppState $state): void
    {
        // One global sending account. Fixed primary key makes concurrent heartbeats idempotent.
        $values = ['status' => $state->status->value, 'phone_number' => $state->phoneNumber, 'last_error' => null];
        if ($state->status === WhatsAppStatus::CONNECTED) {
            $values['last_connected_at'] = now();
        }
        WhatsappSession::upsert([['id' => '00000000-0000-4000-8000-000000000001', ...$values]], ['id'], array_keys($values));
    }
}
