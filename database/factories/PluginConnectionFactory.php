<?php

namespace Database\Factories;

use App\Models\PluginConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PluginConnection>
 */
class PluginConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'app_slug' => 'github', 'access_token' => 'gho_'.fake()->regexify('[A-Za-z0-9]{36}'), 'external_login' => fake()->userName(),
            'scopes' => 'read:org,read:user,repo', 'status' => 'active', 'connected_at' => now()];
    }

    public function needsReconnect(): static
    {
        return $this->state(fn () => ['status' => 'needs_reconnect']);
    }
}
