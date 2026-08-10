<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;

/**
 * Adapter secondario di test: implementa CommunicationRepository in memoria,
 * senza Eloquent ne' database. Dimostra la promessa di ADR 0010 ("i casi
 * d'uso diventano testabili passando mock delle porte, non fake HTTP o
 * LocalStack") con un adapter vero, non un mock generico.
 */
final class InMemoryCommunicationRepository implements CommunicationRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $nextId = 1;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function seed(int $id, array $attributes = []): void
    {
        $this->rows[$id] = array_merge($this->defaults($id), $this->normalize($attributes));
        $this->nextId = max($this->nextId, $id + 1);
    }

    public function createCommunication(array $attributes): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = array_merge($this->defaults($id), $this->normalize($attributes));

        return $id;
    }

    public function findCommunication(int $id): CommunicationRecord
    {
        if (! isset($this->rows[$id])) {
            throw new \RuntimeException("Communication {$id} non seminata nel fake repository.");
        }

        return $this->toRecord($this->rows[$id]);
    }

    public function updateCommunication(int $id, array $attributes): void
    {
        if (! isset($this->rows[$id])) {
            throw new \RuntimeException("Communication {$id} non seminata nel fake repository.");
        }

        $this->rows[$id] = array_merge($this->rows[$id], $this->normalize($attributes));
    }

    public function deleteCommunication(int $id): void
    {
        unset($this->rows[$id]);
    }

    public function paginateApprovedCommunications(string $tenantId, array $filters, int $page, int $perPage): array
    {
        return ['ids' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value,
            $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(int $id): array
    {
        return [
            'id' => $id,
            'tenant_id' => 'tenant-test',
            'prompt' => 'Prompt di test',
            'tone' => 'Chiaro e diretto',
            'style' => 'Testo informativo',
            'generated_title' => null,
            'generated_body' => null,
            'image_prompt' => null,
            'generation_status' => 'pending',
            'cover_image_path' => null,
            'cover_image_mime' => null,
            'cover_status' => 'pending',
            'status' => 'draft',
            'is_favorite' => false,
            'rating' => null,
            'workflow_execution_arn' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toRecord(array $row): CommunicationRecord
    {
        return new CommunicationRecord(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            prompt: $row['prompt'],
            tone: $row['tone'],
            style: $row['style'],
            generatedTitle: $row['generated_title'],
            generatedBody: $row['generated_body'],
            imagePrompt: $row['image_prompt'],
            generationStatus: $row['generation_status'],
            coverImagePath: $row['cover_image_path'],
            coverImageMime: $row['cover_image_mime'],
            coverStatus: $row['cover_status'],
            status: $row['status'],
            isFavorite: (bool) $row['is_favorite'],
            rating: $row['rating'],
            workflowExecutionArn: $row['workflow_execution_arn'],
        );
    }
}
