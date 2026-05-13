<?php

namespace Database\Factories\Poc\Models;

use App\Poc\Enums\ProcessingStatus;
use App\Poc\Models\OriginalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OriginalDocument>
 */
class OriginalDocumentFactory extends Factory
{
    protected $model = \App\Poc\Models\OriginalDocument::class;

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
