<?php

namespace Database\Factories;

use App\Models\PluginApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PluginApp>
 */
class PluginAppFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['slug' => 'github', 'name' => 'GitHub', 'is_enabled' => false, 'agent_ids' => [], 'tools' => null];
    }

    /** Switched on for the given chatbots. */
    public function enabledFor(int ...$agentIds): static
    {
        return $this->state(fn () => ['is_enabled' => true, 'agent_ids' => $agentIds]);
    }
}
