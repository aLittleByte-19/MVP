<?php

namespace Database\Factories;

use App\Enums\ProcessingStatus;
use App\Models\OriginalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OriginalDocument>
 */
class OriginalDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'file_path' => 'documents/originals/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'_cedolini.pdf',
            'processing_status' => fake()->randomElement(ProcessingStatus::cases()),
        ];
    }

    public function completed(): static
    {
        return $this->state(['processing_status' => ProcessingStatus::Completed]);
    }

    public function failed(): static
    {
        return $this->state(['processing_status' => ProcessingStatus::Failed]);
    }
}
