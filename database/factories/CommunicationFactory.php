<?php

namespace Database\Factories;

use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'prompt' => fake()->paragraph(),
            'tone' => fake()->randomElement(['formal', 'informale', 'persuasivo']),
            'style' => fake()->randomElement(['newsletter', 'comunicato', 'memo']),
            'generated_title' => fake()->sentence(),
            'generated_body' => fake()->paragraphs(3, true),
            'status' => fake()->randomElement(CommunicationStatus::cases()),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => CommunicationStatus::Draft]);
    }

    public function discarded(): static
    {
        return $this->state(['status' => CommunicationStatus::Discarded]);
    }
}
