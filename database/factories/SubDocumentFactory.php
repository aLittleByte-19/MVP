<?php

namespace Database\Factories;

use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubDocument>
 */
class SubDocumentFactory extends Factory
{
    protected $model = SubDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startPage = fake()->numberBetween(1, 10);

        return [
            'original_document_id' => OriginalDocument::factory(),
            'file_path' => 'documents/sub/'.fake()->numberBetween(1, 100).'/'.fake()->uuid().'.pdf',
            'start_page' => $startPage,
            'end_page' => $startPage + fake()->numberBetween(1, 10),
            'send_status' => SendStatus::Pending,
            'review_status' => ReviewStatus::NeedsReview,
        ];
    }

    public function pending(): static
    {
        return $this->state(['send_status' => SendStatus::Pending]);
    }

    public function sent(): static
    {
        return $this->state(['send_status' => SendStatus::Sent]);
    }

    /** Dati confermati da una persona: e' la condizione che sblocca il download. */
    public function confirmed(): static
    {
        return $this->state(['review_status' => ReviewStatus::ManuallyValidated]);
    }
}
