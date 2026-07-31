<?php

namespace Database\Factories;

use App\Models\Communication;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageSource;
use App\Mvp\Communications\Enums\CoverImageStatus;
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
            'tenant_id' => 'mvp-local-tenant',
            'created_by' => 'mvp-local-user',
            'prompt' => fake()->paragraph(),
            'tone' => fake()->randomElement(['formal', 'informale', 'persuasivo']),
            'style' => fake()->randomElement(['newsletter', 'comunicato', 'memo']),
            'generated_title' => fake()->sentence(),
            'generated_body' => fake()->paragraphs(3, true),
            'generation_status' => CommunicationGenerationStatus::Completed,
            'workflow_completed_at' => now(),
            'cover_status' => CoverImageStatus::Removed,
            'status' => fake()->randomElement(CommunicationStatus::cases()),
            'rating' => null,
            'rating_comment' => null,
            'rated_at' => null,
            'rated_by' => null,
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

    public function processing(): static
    {
        return $this->state([
            'generated_title' => null,
            'generated_body' => null,
            'generation_status' => CommunicationGenerationStatus::Processing,
            'workflow_started_at' => now(),
            'workflow_completed_at' => null,
            'cover_status' => CoverImageStatus::Pending,
            'status' => CommunicationStatus::Draft,
        ]);
    }

    public function coverReady(): static
    {
        return $this->state([
            'cover_image_path' => 'communications/covers/1/'.fake()->uuid().'.png',
            'cover_image_mime' => 'image/png',
            'cover_image_size' => 2048,
            'cover_image_source' => CoverImageSource::Ai,
            'cover_status' => CoverImageStatus::Ready,
            'cover_error' => null,
        ]);
    }

    public function coverFailed(): static
    {
        return $this->state([
            'cover_image_path' => null,
            'cover_status' => CoverImageStatus::Failed,
            'cover_error' => 'Copertina non disponibile: il modello immagini non ha restituito un risultato.',
        ]);
    }

    public function rated(int $rating = 4, ?string $comment = null): static
    {
        return $this->state([
            'rating' => $rating,
            'rating_comment' => $comment,
            'rated_at' => now(),
            'rated_by' => 'mvp-local-user',
        ]);
    }
}
