<?php

namespace Database\Factories\Copilot;

use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Models\Copilot\OriginalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OriginalDocument>
 */
class OriginalDocumentFactory extends Factory
{
    protected $model = OriginalDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => 'mvp-local-tenant',
            'created_by' => 'mvp-local-user',
            'file_path' => 'documents/originals/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'_cedolini.pdf',
            'processing_status' => fake()->randomElement(ProcessingStatus::cases()),
            'ocr_text' => "[Pagina 1]\n".fake()->paragraph(),
            'ocr_confidence_avg' => 97.5,
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
