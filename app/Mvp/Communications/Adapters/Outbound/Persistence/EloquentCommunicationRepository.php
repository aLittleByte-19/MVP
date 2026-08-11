<?php

namespace App\Mvp\Communications\Adapters\Outbound\Persistence;

use App\Models\Communication;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;
use App\Mvp\Communications\Enums\CommunicationStatus;

/**
 * Adapter secondario: implementa {@see CommunicationRepository} sopra Eloquent.
 */
class EloquentCommunicationRepository implements CommunicationRepository
{
    public function createCommunication(array $attributes): int
    {
        return Communication::create($attributes)->id;
    }

    public function findCommunication(int $id): CommunicationRecord
    {
        return $this->toRecord(Communication::query()->findOrFail($id));
    }

    public function updateCommunication(int $id, array $attributes): void
    {
        Communication::query()->whereKey($id)->firstOrFail()->update($attributes);
    }

    public function deleteCommunication(int $id): void
    {
        Communication::query()->whereKey($id)->firstOrFail()->delete();
    }

    public function paginateApprovedCommunications(string $tenantId, array $filters, int $page, int $perPage): array
    {
        $query = Communication::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CommunicationStatus::Approved);

        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where('prompt', 'like', '%'.$keyword.'%');
        }

        foreach (['tone', 'style'] as $exact) {
            if ($value = trim((string) ($filters[$exact] ?? ''))) {
                $query->where($exact, $value);
            }
        }

        if ($date = $filters['date'] ?? null) {
            $query->whereDate('created_at', $date);
        }

        $paginator = $query->latest()->paginate(perPage: $perPage, page: $page, columns: ['id']);

        return [
            'ids' => $paginator->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    private function toRecord(Communication $communication): CommunicationRecord
    {
        return new CommunicationRecord(
            id: $communication->id,
            tenantId: $communication->tenant_id,
            prompt: $communication->prompt,
            tone: $communication->tone,
            style: $communication->style,
            generatedTitle: $communication->generated_title,
            generatedBody: $communication->generated_body,
            imagePrompt: $communication->image_prompt,
            generationStatus: $communication->generation_status->value,
            coverImagePath: $communication->cover_image_path,
            coverImageMime: $communication->cover_image_mime,
            coverStatus: $communication->cover_status->value,
            status: $communication->status->value,
            isFavorite: (bool) $communication->is_favorite,
            rating: $communication->rating,
            workflowExecutionArn: $communication->workflow_execution_arn,
            coverError: $communication->cover_error,
            errorMessage: $communication->error_message,
        );
    }
}
