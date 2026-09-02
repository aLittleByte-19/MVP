<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\Entities\Communication;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationListFilters;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPage;
use App\Mvp\Communications\Domain\ValueObjects\NewCommunication;

/**
 * Porta secondaria verso la persistenza dell'aggregato Communication.
 * Nessun riferimento a Eloquent: le letture restituiscono un'entita' ricca
 * ({@see Communication}, ADR 0010), le scritture prendono value object di
 * dominio invece di array associativi con chiavi a stringa.
 */
interface CommunicationRepository
{
    public function createCommunication(NewCommunication $communication): int;

    public function findCommunication(int $id): Communication;

    /**
     * Come findCommunication(), ma con lock pessimistico sulla riga
     * (SELECT ... FOR UPDATE). Da usare solo dentro TransactionManagerPort.
     */
    public function findCommunicationForUpdate(int $id): Communication;

    public function updateCommunication(int $id, CommunicationChanges $changes): void;

    /**
     * Persiste le modifiche accumulate sull'entita' (vedi
     * {@see Communication::pendingChanges()}). Coesiste con
     * updateCommunication()/CommunicationChanges per le scritture che non
     * passano da una transizione governata (ADR 0010).
     */
    public function saveCommunication(Communication $communication): void;

    public function deleteCommunication(int $id): void;

    public function paginateApprovedCommunications(string $tenantId, CommunicationListFilters $filters, int $page, int $perPage): CommunicationPage;
}
