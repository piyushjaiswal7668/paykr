<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WhatsappSession> */
class WhatsappSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status' => 'DISCONNECTED',
            'phone_number' => null,
            'last_connected_at' => null,
            'last_error' => null,
        ];
    }
}
