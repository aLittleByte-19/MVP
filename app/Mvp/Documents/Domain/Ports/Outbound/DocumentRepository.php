<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

use App\Mvp\Documents\Domain\Entities\OriginalDocument;
use App\Mvp\Documents\Domain\Entities\SubDocument;
use App\Mvp\Documents\Domain\ValueObjects\DocumentListFilters;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Documents\Domain\ValueObjects\NewOriginalDocument;
use App\Mvp\Documents\Domain\ValueObjects\NewSubDocument;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;

/**
 * Porta secondaria verso la persistenza dell'aggregato documentale
 * (OriginalDocument + SubDocument + ExtractedData). Nessun riferimento a
 * Eloquent: le letture restituiscono entità che governano le proprie
 * transizioni di stato ({@see OriginalDocument}, {@see SubDocument} —
 * modello ricco, ADR 0010); le scritture prendono value object di dominio
 * invece di array associativi, cosi' il nome della colonna DB resta un
 * dettaglio di quelle classi.
 *
 * `paginateSubDocuments` restituisce solo id e metadati di paginazione: la
 * presentazione HTTP (relazioni Eloquent per `MvpStateService`) resta fuori
 * perimetro e ricarica gli id qui restituiti (ADR 0010).
 */
interface DocumentRepository
{
    public function createOriginalDocument(NewOriginalDocument $document): int;

    public function findOriginalDocument(int $id): OriginalDocument;

    /**
     * Come {@see self::findOriginalDocument()}, ma con lock pessimistico sulla
     * riga: rende atomico il controllo "e' gia' in corso?" seguito da una
     * scrittura (es. l'avvio del workflow), cosi' due richieste quasi
     * simultanee non superino entrambe il controllo.
     */
    public function findOriginalDocumentForUpdate(int $id): OriginalDocument;

    public function updateOriginalDocument(int $id, OriginalDocumentChanges $changes): void;

    /**
     * Persiste le modifiche accumulate sull'entità ({@see OriginalDocument::pendingChanges()}).
     * Coesiste con updateOriginalDocument() per le scritture che non passano
     * da una transizione governata (es. i campi OCR, scritti da RunOcrService).
     */
    public function saveOriginalDocument(OriginalDocument $document): void;

    public function deleteOriginalDocumentWithWorkflowTasks(int $id): void;

    public function paginateSubDocuments(string $tenantId, DocumentListFilters $filters, int $page, int $perPage): SubDocumentPage;

    public function findSubDocument(int $id): SubDocument;

    /**
     * Persiste le modifiche accumulate sull'entità ({@see SubDocument::pendingChanges()}).
     * Coesiste con updateSubDocument() per le scritture che non passano
     * dall'entità (es. gli override di invio via SendMessageContext).
     */
    public function saveSubDocument(SubDocument $subDocument): void;

    public function findSendMessageContext(int $subDocumentId): SendMessageContext;

    /**
     * True se il campo dati estratti esiste per il sotto-documento (serve a
     * decidere se e' possibile validare manualmente, UC-9bis/UC-52).
     */
    public function subDocumentHasExtractedData(int $subDocumentId): bool;

    public function createSubDocument(NewSubDocument $subDocument): int;

    public function updateSubDocument(int $id, SubDocumentChanges $changes): void;

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
     * Numero di sotto-documenti di un originale (indipendentemente dai dati
     * estratti): campo diagnostico nella risposta del task workflow
     * "bedrock.extract".
     */
    public function countSubDocuments(int $originalDocumentId): int;

    /**
     * Elimina i sotto-documenti esistenti di un originale (rielaborazione) e
     * restituisce i percorsi storage da ripulire.
     *
     * @return list<string>
     */
    public function deleteExistingSubDocuments(int $originalDocumentId): array;

    public function saveExtractedData(int $subDocumentId, ExtractedDataChanges $changes): void;

    public function deleteExtractedData(int $subDocumentId): void;
}
