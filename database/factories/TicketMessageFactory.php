<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TicketMessage> */
class TicketMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => app(TenantContext::class)->merchantId() ?? Merchant::factory(),
            'ticket_id' => fn (array $attributes) => SupportTicket::factory()->create(['merchant_id' => $attributes['merchant_id']])->id,
            'sender_type' => 'CUSTOMER',
            'sender_id' => (string) Str::uuid(),
            'message' => 'Test message',
            'attachments' => null,
        ];
    }
}
