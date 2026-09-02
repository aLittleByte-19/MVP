<?php

namespace Database\Factories;

use App\Models\PromptConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptConfiguration>
 */
class PromptConfigurationFactory extends Factory
{
    protected $model = PromptConfiguration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => 'mvp-local-tenant',
            'created_by' => 'mvp-local-user',
            'name' => fake()->unique()->words(2, true),
            'prompt' => fake()->paragraph(),
            'tone' => fake()->randomElement(['Chiaro e diretto', 'Più istituzionale', 'Più sintetico', 'Empatico', 'Tecnico']),
            'style' => fake()->randomElement(['Testo informativo', 'Avviso operativo', 'Aggiornamento breve']),
        ];
    }
}
