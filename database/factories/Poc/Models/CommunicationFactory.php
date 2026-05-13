<?php

namespace Database\Factories\Poc\Models;

use App\Poc\Enums\CommunicationStatus;
use App\Poc\Models\Communication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    protected $model = \App\Poc\Models\Communication::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Indicate that the communication is a draft.
     *
     * @return static
     */
    public function draft(): static
    {
        return $this->state(['status' => CommunicationStatus::Draft]);
    }

    /**
     * Indicate that the communication is approved.
     *
     * @return static
     */
    public function approved(): static
    {
        return $this->state(['status' => CommunicationStatus::Approved]);
    }

    /**
     * Indicate that the communication is discarded.
     *
     * @return static
     */
    public function discarded(): static
    {
        return $this->state(['status' => CommunicationStatus::Discarded]);
    }
}
