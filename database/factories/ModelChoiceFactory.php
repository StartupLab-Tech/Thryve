<?php

namespace Database\Factories;

use App\Models\ModelChoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelChoice>
 */
class ModelChoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'label' => 'Best',
            'hint' => 'For hard work',
            'supports_images' => false,
            'is_enabled' => false,
            'is_default' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn () => ['is_enabled' => true]);
    }
}
