<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;

/**
 * Porta secondaria verso la persistenza dell'aggregato documentale
 * (OriginalDocument + SubDocument + ExtractedData). Nessun riferimento a
 * Eloquent: i metodi di lettura restituiscono value object di dominio
 * ({@see OriginalDocumentRecord}, {@see SubDocumentRecord},
 * {@see SubDocumentPage}); le scritture prendono parametri primitivi — chi
 * implementa questa interfaccia decide come tradurli in una mutazione
 * Eloquent, il chiamante non costruisce mai la query o la mutazione stessa.
 *
 * `paginateSubDocuments` restituisce solo identificativi e metadati di
 * paginazione: e' una scelta di perimetro esplicita (vedi ADR 0010) — la
 * ricerca/filtro e' la decisione che deve passare dalla porta, la forma di
 * presentazione HTTP (che richiede le relazioni Eloquent caricate per
 * `MvpStateService`, rimasta fuori perimetro) resta responsabilita'
 * dell'adapter primario, che ri-carica gli id restituiti qui.
 */
interface DocumentRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOriginalDocument(array $attributes): int;

    public function findOriginalDocument(int $id): OriginalDocumentRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOriginalDocument(int $id, array $attributes): void;

    public function deleteOriginalDocumentWithWorkflowTasks(int $id): void;

    /**
     * @param  array{
     *     search?: ?string,
     *     sendStatus?: ?string,
     *     confidenceThreshold?: ?int,
     *     confidenceCriterion?: ?string,
     *     month?: ?int,
     *     year?: ?int,
     * }  $filters
     */
    public function paginateSubDocuments(string $tenantId, array $filters, int $page, int $perPage): SubDocumentPage;

    public function findSubDocument(int $id): SubDocumentRecord;

    public function findSendMessageContext(int $subDocumentId): SendMessageContext;

    /**
     * True se il campo dati estratti esiste per il sotto-documento (serve a
     * decidere se e' possibile validare manualmente, UC-9bis/UC-52).
     */
    public function subDocumentHasExtractedData(int $subDocumentId): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSubDocument(array $attributes): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSubDocument(int $id, array $attributes): void;

    public function deleteSubDocument(int $id): void;

    /**
     * Id del documento originale a cui appartiene un sotto-documento.
     */
    public function originalDocumentIdForSubDocument(int $subDocumentId): int;

    public function originalDocumentHasRemainingSubDocuments(int $originalDocumentId): bool;

    /**
     * Id, in ordine, dei sotto-documenti di un originale che hanno gia' dati
     * estratti (serve al polling di avanzamento, UC-35/UC-36).
     *
     * @return list<int>
     */
    public function subDocumentIdsWithExtractedData(int $originalDocumentId): array;

    /**
     * Elimina i sotto-documenti esistenti di un originale (rielaborazione) e
     * restituisce i percorsi storage da ripulire.
     *
     * @return list<string>
     */
    public function deleteExistingSubDocuments(int $originalDocumentId): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveExtractedData(int $subDocumentId, array $attributes): void;

    public function deleteExtractedData(int $subDocumentId): void;
}
