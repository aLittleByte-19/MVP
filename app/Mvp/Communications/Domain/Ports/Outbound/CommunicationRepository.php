<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPage;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;
use App\Mvp\Communications\Domain\ValueObjects\NewCommunication;

/**
 * Porta secondaria verso la persistenza dell'aggregato Communication.
 * Nessun riferimento a Eloquent: le letture restituiscono
 * {@see CommunicationRecord}, le scritture prendono value object di
 * dominio ({@see NewCommunication}, {@see CommunicationChanges}) invece di
 * array associativi con chiavi a stringa: il nome della colonna DB resta un
 * dettaglio di quelle classi (Domain), non qualcosa che ogni caso d'uso
 * deve scrivere a mano (stessa scelta di DocumentRepository, vedi ADR 0010).
 */
interface CommunicationRepository
{
    public function createCommunication(NewCommunication $communication): int;

    public function findCommunication(int $id): CommunicationRecord;

    public function updateCommunication(int $id, CommunicationChanges $changes): void;

    public function deleteCommunication(int $id): void;

    /**
     * @param  array{keyword?: ?string, tone?: ?string, style?: ?string, date?: ?string}  $filters
     */
    public function paginateApprovedCommunications(string $tenantId, array $filters, int $page, int $perPage): CommunicationPage;
}
