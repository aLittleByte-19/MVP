<?php

namespace Database\Factories\Copilot;

use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\SubDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtractedData>
 */
class ExtractedDataFactory extends Factory
{
    protected $model = ExtractedData::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sub_document_id' => SubDocument::factory(),
            'employee_first_name' => fake()->firstName(),
            'employee_last_name' => fake()->lastName(),
            'company_name' => fake()->company(),
            'document_date' => fake()->date('Y-m-d'),
            'document_type' => fake()->randomElement(['Cedolino', 'CUD', 'Busta Paga', 'Contratto']),
            'description' => fake()->sentence(10),
            'confidence_score' => fake()->numberBetween(60, 99),
        ];
    }

    public function withNullFields(): static
    {
        return $this->state([
            'employee_first_name' => null,
            'employee_last_name' => null,
            'company_name' => null,
            'document_date' => null,
            'document_type' => null,
            'description' => null,
            'confidence_score' => null,
        ]);
    }
}
