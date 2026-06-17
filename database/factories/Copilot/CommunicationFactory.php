<?php

namespace Database\Factories\Copilot;

use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Models\Copilot\Communication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    protected $model = Communication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => 'poc-local-tenant',
            'created_by' => 'poc-local-user',
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

    public function approved(): static
    {
        return $this->state(['status' => CommunicationStatus::Approved]);
    }

    public function discarded(): static
    {
        return $this->state(['status' => CommunicationStatus::Discarded]);
    }
}
