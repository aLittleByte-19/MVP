<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;

/**
 * Porta secondaria verso la persistenza dell'aggregato Communication.
 * Nessun riferimento a Eloquent: le letture restituiscono
 * {@see CommunicationRecord}, le scritture prendono parametri primitivi
 * (stessa scelta di perimetro di DocumentRepository, vedi ADR 0010).
 */
interface CommunicationRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCommunication(array $attributes): int;

    public function findCommunication(int $id): CommunicationRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCommunication(int $id, array $attributes): void;

    public function deleteCommunication(int $id): void;

    /**
     * @param  array{keyword?: ?string, tone?: ?string, style?: ?string, date?: ?string}  $filters
     * @return array{ids: list<int>, total: int, page: int, perPage: int}
     */
    public function paginateApprovedCommunications(string $tenantId, array $filters, int $page, int $perPage): array;
}
