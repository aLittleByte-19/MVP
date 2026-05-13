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

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_path' => 'documents/originals/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'_cedolini.pdf',
            'processing_status' => fake()->randomElement(ProcessingStatus::cases()),
        ];
    }

    /**
     * Indicate that the document processing is completed.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(['processing_status' => ProcessingStatus::Completed]);
    }

    /**
     * Indicate that the document processing failed.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(['processing_status' => ProcessingStatus::Failed]);
    }
}
