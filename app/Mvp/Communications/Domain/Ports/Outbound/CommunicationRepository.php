<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\Entities\Communication;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPage;
use App\Mvp\Communications\Domain\ValueObjects\NewCommunication;

/**
 * Porta secondaria verso la persistenza dell'aggregato Communication.
 * Nessun riferimento a Eloquent: le letture restituiscono un'entità che
 * governa le proprie transizioni di stato ({@see Communication} — Progetto
 * A, modello ricco, vedi ADR 0010), le scritture prendono value object di
 * dominio ({@see NewCommunication}, {@see CommunicationChanges}) invece di
 * array associativi con chiavi a stringa: il nome della colonna DB resta un
 * dettaglio di quelle classi (Domain), non qualcosa che ogni caso d'uso
 * deve scrivere a mano (stessa scelta di DocumentRepository, vedi ADR 0010).
 */
interface CommunicationRepository
{
    public function createCommunication(NewCommunication $communication): int;

    public function findCommunication(int $id): Communication;

    public function updateCommunication(int $id, CommunicationChanges $changes): void;

    /**
     * Persiste le modifiche accumulate sull'entità (vedi
     * {@see Communication::pendingChanges()}). Coesiste con
     * updateCommunication()/CommunicationChanges per le scritture che non
     * passano da una transizione governata (es. il marcatore transitorio
     * coverStatus=Processing e l'errorMessage scritto da
     * GenerateCommunicationTextService prima che il workflow fallisca —
     * vedi ADR 0010).
     */
    public function saveCommunication(Communication $communication): void;

    public function deleteCommunication(int $id): void;

    /**
     * @param  array{keyword?: ?string, tone?: ?string, style?: ?string, date?: ?string}  $filters
     */
    public function paginateApprovedCommunications(string $tenantId, array $filters, int $page, int $perPage): CommunicationPage;
}
