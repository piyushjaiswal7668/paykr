<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlatformOption> */
final class PlatformOptionFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => 'test-'.fake()->uuid(), 'value' => ['app_name' => 'Test Pay']];
    }
}
