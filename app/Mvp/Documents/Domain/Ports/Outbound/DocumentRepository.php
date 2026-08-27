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
 * Eloquent: i metodi di lettura restituiscono value object di dominio
 * ({@see SubDocumentPage}) o entità che governano le proprie transizioni di
 * stato ({@see OriginalDocument}, {@see SubDocument} — Progetto A, modello
 * ricco, vedi ADR 0010); le scritture prendono value object di dominio
 * ({@see NewOriginalDocument}, {@see OriginalDocumentChanges},
 * {@see NewSubDocument}, {@see SubDocumentChanges},
 * {@see ExtractedDataChanges}) invece di array associativi con chiavi a
 * stringa: il nome della colonna DB resta un dettaglio di quelle classi
 * (Domain), non qualcosa che ogni caso d'uso deve scrivere a mano.
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
    public function createOriginalDocument(NewOriginalDocument $document): int;

    public function findOriginalDocument(int $id): OriginalDocument;

    /**
     * Come {@see self::findOriginalDocument()}, ma con un lock pessimistico
     * sulla riga: serve a rendere atomico un controllo "e' gia' in corso?"
     * seguito da una scrittura (es. l'avvio del workflow, StartDocumentWorkflowService),
     * cosi' due richieste quasi simultanee non superino entrambe il controllo
     * e avviino due esecuzioni per lo stesso documento.
     */
    public function findOriginalDocumentForUpdate(int $id): OriginalDocument;

    public function updateOriginalDocument(int $id, OriginalDocumentChanges $changes): void;

    /**
     * Persiste le modifiche accumulate sull'entità (vedi
     * {@see OriginalDocument::pendingChanges()}). Coesiste con
     * updateOriginalDocument()/OriginalDocumentChanges per le scritture che
     * non passano da una transizione governata (es. i campi OCR, scritti da
     * RunOcrService — vedi ADR 0010).
     */
    public function saveOriginalDocument(OriginalDocument $document): void;

    public function deleteOriginalDocumentWithWorkflowTasks(int $id): void;

    public function paginateSubDocuments(string $tenantId, DocumentListFilters $filters, int $page, int $perPage): SubDocumentPage;

    public function findSubDocument(int $id): SubDocument;

    /**
     * Persiste le modifiche accumulate sull'entità (vedi
     * {@see SubDocument::pendingChanges()}). Coesiste con
     * updateSubDocument()/SubDocumentChanges per le scritture che non
     * passano dall'entità (es. gli override di invio, gestiti da
     * SendMessageService tramite SendMessageContext — vedi ADR 0010).
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
