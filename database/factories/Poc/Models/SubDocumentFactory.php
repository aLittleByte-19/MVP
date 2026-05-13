<?php

namespace Database\Factories\Poc\Models;

use App\Poc\Enums\SendStatus;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubDocument>
 */
class SubDocumentFactory extends Factory
{
    protected $model = \App\Poc\Models\SubDocument::class;

    /**
     * Define the model's default state.
     *
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
        ];
    }

    /**
     * Indicate that the sub-document is pending.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(['send_status' => SendStatus::Pending]);
    }

    /**
     * Indicate that the sub-document has been sent.
     *
     * @return static
     */
    public function sent(): static
    {
        return $this->state(['send_status' => SendStatus::Sent]);
    }
}
