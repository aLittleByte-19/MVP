<?php

namespace App\Mvp\Support;

use App\Models\Communication;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\PromptConfiguration;
use App\Models\SubDocument;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Documents\Services\SubDocumentSendMessageService;
use App\Mvp\Identity\MvpUser;

class MvpStateService
{
    public function __construct(
        private readonly SubDocumentSendMessageService $sendMessages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forActor(MvpUser $actor): array
    {
        return [
            'assistant' => $this->assistantState($actor),
            'copilot' => $this->copilotState($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantState(MvpUser $actor): array
    {
        $baseQuery = Communication::query()->where('tenant_id', $actor->tenantId);
        $total = (clone $baseQuery)->count();
        $drafts = (clone $baseQuery)->where('status', CommunicationStatus::Draft)->count();
        $rated = (clone $baseQuery)->whereNotNull('rating')->count();
        $averageRating = (clone $baseQuery)->whereNotNull('rating')->avg('rating');
        // Una bozza entra nello storico solo dopo un salvataggio esplicito
        // (UC-9): finche' resta draft, o dopo uno scarto (UC-7), non deve
        // comparire qui, e' l'operatore a decidere cosa fissare nello storico.
        $history = (clone $baseQuery)
            ->where('status', CommunicationStatus::Approved)
            ->latest()
            ->limit(10)
            ->get();
        // Preset di prompt salvati (UC-19): elenco limitato, non filtrabile,
        // pensato per un riuso rapido dal form di generazione, non come
        // archivio ricercabile.
        $promptConfigurations = PromptConfiguration::query()
            ->where('tenant_id', $actor->tenantId)
            ->latest()
            ->limit(20)
            ->get();

        return [
            // La `key` e' l'identificativo stabile: la label e' testo di
            // presentazione e puo' cambiare senza rompere chi seleziona la
            // metrica (vedi overview-page, che ne mostra solo alcune).
            'metrics' => [
                ['key' => 'assistant.total', 'value' => $total, 'label' => 'Contenuti generati'],
                ['key' => 'assistant.drafts', 'value' => $drafts, 'label' => 'Bozze generate'],
                ['key' => 'assistant.rated', 'value' => $rated, 'label' => 'Valutazioni ricevute'],
                [
                    'key' => 'assistant.rating_average',
                    'value' => $averageRating === null ? '—' : number_format((float) $averageRating, 1, '.', ''),
                    'label' => 'Media stelle',
                ],
            ],
            'history' => $history->map(fn ($communication) => $this->communication($communication))->values()->all(),
            'promptConfigurations' => $promptConfigurations->map(fn ($configuration) => $this->promptConfiguration($configuration))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function promptConfiguration(PromptConfiguration $configuration): array
    {
        return [
            'id' => $configuration->id,
            'name' => $configuration->name,
            'prompt' => $configuration->prompt,
            'tone' => $configuration->tone,
            'style' => $configuration->style,
            // ISO, non formattata per la lettura: serve anche a filtrare per
            // data lato frontend (vedi formatDateForDisplay in assistant-page).
            'createdAt' => $configuration->created_at?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function copilotState(MvpUser $actor): array
    {
        $documents = SubDocument::query()
            ->with(['originalDocument', 'extractedData'])
            ->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))
            ->latest()
            ->limit(40)
            ->get();

        $originalCount = OriginalDocument::query()->where('tenant_id', $actor->tenantId)->count();
        $confidenceThreshold = (int) config('services.bedrock.mvp_confidence_threshold', 80);

        $ofTenant = fn ($query) => $query->whereHas('originalDocument', fn ($documents) => $documents->where('tenant_id', $actor->tenantId));

        return [
            'metrics' => [
                ['key' => 'copilot.documents', 'value' => $originalCount, 'label' => 'Documenti analizzati'],
                ['key' => 'copilot.sub_documents', 'value' => $ofTenant(SubDocument::query())->count(), 'label' => 'Sotto-documenti rilevati'],
                ['key' => 'copilot.confident_fields', 'value' => ExtractedData::query()->whereHas('subDocument.originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->where('confidence_score', '>=', $confidenceThreshold)->count(), 'label' => 'Campi con confidenza'],
                ['key' => 'copilot.needs_review', 'value' => $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::NeedsReview)->count(), 'label' => 'Da verificare'],
                // Pronti = validati, automaticamente o a mano. Non e' il
                // complemento di "da verificare": la quarantena e' un terzo
                // stato che non va contato come pronto.
                ['key' => 'copilot.validated', 'value' => $ofTenant(SubDocument::query())->whereIn('review_status', [ReviewStatus::AutoValidated, ReviewStatus::ManuallyValidated])->count(), 'label' => 'Documenti pronti'],
                ['key' => 'copilot.quarantined', 'value' => $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::Quarantined)->count(), 'label' => 'In quarantena'],
            ],
            'documents' => $documents->map(fn ($document) => $this->document($document))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function communication(Communication $communication): array
    {
        return [
            'id' => $communication->id,
            'prompt' => $communication->prompt,
            'tone' => $communication->tone,
            'style' => $communication->style,
            'title' => $communication->generated_title,
            'body' => $communication->generated_body,
            'previewUrl' => route('api.v1.communications.preview', ['communication' => $communication->id], false),
            'exportUrl' => route('api.v1.communications.export', ['communication' => $communication->id], false),
            'coverImageUrl' => $this->coverImageUrl($communication),
            'coverStatus' => $communication->cover_status->value,
            'coverStatusLabel' => $communication->cover_status->label(),
            'coverError' => $communication->cover_error,
            'generationStatus' => $communication->generation_status->value,
            'generationStatusLabel' => $communication->generation_status->label(),
            'error' => $communication->error_message,
            'status' => $communication->status->label(),
            'statusValue' => $communication->status->value,
            'createdAt' => $communication->created_at?->format('d/m/Y H:i'),
            'rating' => $communication->rating,
            'ratingComment' => $communication->rating_comment,
            'ratedAt' => $communication->rated_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * URL relativo dell'endpoint di serving: vedi la nota in
     * DocumentController::store sul mixed-content dietro Traefik.
     *
     * Il percorso non cambia quando la copertina viene sostituita, quindi porta
     * una versione derivata dalla chiave dell'oggetto: senza, il browser
     * continuerebbe a mostrare l'immagine precedente finche' la cache non scade.
     */
    public function coverImageUrl(Communication $communication): ?string
    {
        if (! $communication->cover_image_path) {
            return null;
        }

        return route('api.v1.communications.cover-image.show', [
            'communication' => $communication->id,
            'v' => substr(hash('xxh128', $communication->cover_image_path), 0, 12),
        ], false);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(SubDocument $subDocument): array
    {
        $original = $subDocument->originalDocument;
        $data = $subDocument->extractedData;
        $employee = trim(implode(' ', array_filter([
            $data?->employee_first_name,
            $data?->employee_last_name,
        ])));
        $confidence = $data?->confidence_score;
        $pages = max(1, ((int) $subDocument->end_page - (int) $subDocument->start_page) + 1);
        $previewLines = [
            'Split iniziale: pagine '.$subDocument->start_page.'-'.$subDocument->end_page.'.',
        ];

        if ($subDocument->error_message) {
            $previewLines[] = 'Errore estrazione: '.$subDocument->error_message;
        }

        $sendMessage = $this->sendMessages->compose($subDocument);

        return [
            'id' => 'sub-'.$subDocument->id,
            'title' => $data?->document_type ?: $original?->original_filename,
            'employeeFirstName' => $data?->employee_first_name,
            'employeeLastName' => $data?->employee_last_name,
            'employee' => $employee !== '' ? $employee : null,
            'companyName' => $data?->company_name,
            'recipientEmail' => $data?->recipient_email,
            'fiscalCode' => $data?->fiscal_code,
            'employeeId' => $data?->employee_id,
            'file' => $original?->original_filename,
            // Data in ISO: la formattazione per la lettura e' presentazione e
            // vive nel frontend (`formatDateForDisplay`).
            'documentDate' => $data?->document_date?->format('Y-m-d'),
            'pages' => $pages,
            'documentType' => $data?->document_type,
            'description' => $data?->description,
            'confidence' => $confidence,
            'reviewStatus' => $subDocument->review_status->value,
            'reviewStatusLabel' => $subDocument->review_status->label(),
            'sendStatus' => $subDocument->send_status->value,
            'sendStatusLabel' => $subDocument->send_status->label(),
            'error' => $subDocument->error_message,
            // URL relativo: vedi nota in DocumentController::store. Un URL assoluto
            // sarebbe generato con schema "http://" dietro Traefik e bloccato dal
            // browser (mixed-content sull'iframe e sul fetch dell'anteprima).
            'previewUrl' => route('api.v1.documents.preview', ['subDocument' => $subDocument->id], false),
            'sendRecipient' => $sendMessage['recipient'],
            'sendSubject' => $sendMessage['subject'],
            'sendBody' => $sendMessage['body'],
            'sendPreviewUrl' => route('api.v1.documents.send-preview', ['subDocument' => $subDocument->id], false),
            'sendExportUrl' => route('api.v1.documents.send-export', ['subDocument' => $subDocument->id], false),
            'previewLines' => $previewLines,
        ];
    }
}
