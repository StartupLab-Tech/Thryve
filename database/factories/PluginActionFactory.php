<?php

namespace Database\Factories;

use App\Models\PluginAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PluginAction>
 */
class PluginActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'app_slug' => 'github', 'tool' => 'issue_write', 'kind' => 'write', 'status' => 'pending',
            'summary' => 'issue_write in octo/sandbox: title "Test"', 'arguments' => ['method' => 'create', 'owner' => 'octo', 'repo' => 'sandbox', 'title' => 'Test'],
            'arguments_hash' => hash('sha256', fake()->uuid()), 'expires_at' => now()->addMinutes(30)];
    }
}
