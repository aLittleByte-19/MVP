<?php

namespace App\Mvp\Support;

use App\Models\Communication;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\PromptConfiguration;
use App\Models\SubDocument;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Support\SendMessageDraft;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Support\ValueObjects\AssistantMetricsFilters;
use Illuminate\Database\Eloquent\Builder;

class MvpStateService
{
    /**
     * Giorni coperti dalla serie storica delle metriche.
     */
    private const HISTORY_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function forActor(Actor $actor, ?AssistantMetricsFilters $filters = null): array
    {
        return [
            'assistant' => $this->assistantState($actor, $filters),
            'copilot' => $this->copilotState($actor),
        ];
    }

    /**
     * I filtri (RF38-OB..RF41-OB) si applicano solo qui, mai a copilotState():
     * tono e stile non esistono sui documenti del Co-Pilot.
     *
     * @return array<string, mixed>
     */
    public function assistantState(Actor $actor, ?AssistantMetricsFilters $filters = null): array
    {
        $baseQuery = $this->applyAssistantMetricsFilters(
            Communication::query()->where('tenant_id', $actor->tenantId),
            $filters,
        );
        $total = (clone $baseQuery)->count();
        $drafts = (clone $baseQuery)->where('status', CommunicationStatus::Draft)->count();
        $rated = (clone $baseQuery)->whereNotNull('rating')->count();
        $averageRating = (clone $baseQuery)->whereNotNull('rating')->avg('rating');
        // Una bozza entra nello storico solo dopo un salvataggio esplicito (UC-9).
        $history = (clone $baseQuery)
            ->where('status', CommunicationStatus::Approved)
            ->latest()
            ->limit(10)
            ->get();
        // Preset di prompt (UC-19): elenco fisso, non soggetto ai filtri.
        $promptConfigurationsQuery = PromptConfiguration::query()->where('tenant_id', $actor->tenantId);
        $promptConfigurationsCount = (clone $promptConfigurationsQuery)->count();
        $promptConfigurations = (clone $promptConfigurationsQuery)->latest()->limit(20)->get();
        // UC-27.3: gli ultimi feedback testuali, non solo la media (UC-27.2).
        $recentFeedback = (clone $baseQuery)
            ->whereNotNull('rating')
            ->latest('rated_at')
            ->limit(10)
            ->get();

        return [
            // La `key` e' l'identificativo stabile: la label e' testo di
            // presentazione e puo' cambiare senza rompere chi seleziona la
            // metrica (vedi overview-page, che ne mostra solo alcune).
            'metrics' => [
                // Ordine pensato per riempire la griglia a coppie senza righe spaiate.
                [
                    'key' => 'assistant.total',
                    'value' => $total,
                    'label' => 'Contenuti generati',
                    'history' => $this->dailySeries($baseQuery),
                ],
                [
                    // UC-27.1: il secondo conteggio di "statistiche di utilizzo",
                    // accanto al totale dei contenuti generati.
                    'key' => 'assistant.prompt_configurations',
                    'value' => $promptConfigurationsCount,
                    'label' => 'Configurazioni di prompt salvate',
                ],
                [
                    'key' => 'assistant.rated',
                    'value' => $rated,
                    'outOf' => $total,
                    'label' => 'Bozze valutate',
                ],
                [
                    // Nessuna serie: e' una media, non un conteggio di ingressi.
                    'key' => 'assistant.rating_average',
                    'value' => $averageRating === null ? '—' : number_format((float) $averageRating, 1, '.', ''),
                    'unit' => '/ 5',
                    'sampleSize' => $rated,
                    'label' => 'Media stelle',
                ],
                [
                    // Non compare nel pannello: e' la parte "in bozza" della
                    // ripartizione e, come priorita', vive nella Overview.
                    'key' => 'assistant.drafts',
                    'value' => $drafts,
                    'label' => 'Bozze generate',
                    'history' => $this->dailySeries((clone $baseQuery)->where('status', CommunicationStatus::Draft)),
                ],
            ],
            'history' => $history->map(fn ($communication) => $this->communication($communication))->values()->all(),
            'promptConfigurations' => $promptConfigurations->map(fn ($configuration) => $this->promptConfiguration($configuration))->values()->all(),
            // UC-27.3: gli ultimi feedback con voto e commento testuale.
            'recentFeedback' => $recentFeedback->map(fn ($communication) => $this->communication($communication))->values()->all(),
        ];
    }

    /**
     * Stessa logica (trim, ignora se vuoto) di
     * EloquentCommunicationRepository::paginateApprovedCommunications() per
     * tono e stile; la data pero' e' un intervallo (RF39-OB), non un giorno
     * singolo come nello storico — ciascun estremo e' indipendente, cosi' un
     * solo lato valorizzato apre l'intervallo invece di richiederli entrambi.
     *
     * @param  Builder<Communication>  $query
     * @return Builder<Communication>
     */
    private function applyAssistantMetricsFilters(Builder $query, ?AssistantMetricsFilters $filters): Builder
    {
        if ($filters === null) {
            return $query;
        }

        if ($tone = trim((string) ($filters->tone ?? ''))) {
            $query->where('tone', $tone);
        }

        if ($style = trim((string) ($filters->style ?? ''))) {
            $query->where('style', $style);
        }

        if ($filters->dateFrom !== null) {
            $query->whereDate('created_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->whereDate('created_at', '<=', $filters->dateTo);
        }

        return $query;
    }

    /**
     * Conteggio giornaliero degli ultimi 7 giorni (zeri espliciti sui giorni
     * vuoti): e' un flusso di ingresso, non lo stock ("3 nuovi oggi", non
     * "+3 rispetto a ieri"), perche' il modello dati non ha snapshot storici.
     * Raggruppato in PHP, non SQL, per restare compatibile fra Postgres e SQLite.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    private function dailySeries($query): array
    {
        $since = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();

        $countsByDay = (clone $query)
            ->where('created_at', '>=', $since)
            ->pluck('created_at')
            ->filter()
            ->countBy(fn ($createdAt): string => $createdAt->format('Y-m-d'));

        $series = [];

        for ($ago = self::HISTORY_DAYS - 1; $ago >= 0; $ago--) {
            $series[] = (int) ($countsByDay[now()->subDays($ago)->format('Y-m-d')] ?? 0);
        }

        return $series;
    }

    /**
     * Durate in secondi delle corse concluse negli ultimi sette giorni.
     * Differenza calcolata in PHP per la stessa ragione di `dailySeries`.
     *
     * @param  Builder<OriginalDocument>|Builder<Communication>  $query
     * @return list<float>
     */
    private function workflowDurations(Builder $query): array
    {
        $since = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();

        return (clone $query)
            ->whereNotNull('workflow_started_at')
            ->whereNotNull('workflow_completed_at')
            ->where('workflow_completed_at', '>=', $since)
            ->get(['workflow_started_at', 'workflow_completed_at'])
            ->map(fn ($row): float => (float) $row->workflow_started_at->diffInSeconds($row->workflow_completed_at, true))
            ->values()
            ->all();
    }

    /**
     * Durata media in secondi, sulla stessa finestra di sette giorni delle
     * durate grezze in ingresso.
     *
     * @param  list<float>  $durations
     * @return array<string, mixed>
     */
    private function averageDurationMetric(string $key, string $label, array $durations): array
    {
        $average = $durations === [] ? null : array_sum($durations) / count($durations);

        return $this->durationMetric($key, $label, $average);
    }

    /**
     * UC-56.4: percentuale di sotto-documenti dove nome, cognome ed email del
     * destinatario coincidono ancora con `ai_payload` (lo snapshot immutabile
     * della prima estrazione), cioe' nessuno li ha corretti a mano da allora.
     * Chi non ha `ai_payload` resta fuori dal denominatore; tre `null` contro
     * tre `null` non conta come corrispondenza (due assenze, non un match).
     *
     * @return array<string, mixed>
     */
    private function recipientAutoMatchMetric(string $tenantId): array
    {
        $rows = ExtractedData::query()
            ->whereHas('subDocument.originalDocument', fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereNotNull('ai_payload')
            ->get(['employee_first_name', 'employee_last_name', 'recipient_email', 'ai_payload']);

        $comparable = 0;
        $matched = 0;

        foreach ($rows as $row) {
            $original = $row->ai_payload;

            if (! is_array($original)) {
                continue;
            }

            $comparable++;

            $aiRecognizedSomething = ($original['employee_first_name'] ?? null) !== null
                || ($original['employee_last_name'] ?? null) !== null
                || ($original['recipient_email'] ?? null) !== null;

            $sameFirstName = $this->sameNormalized($row->employee_first_name, $original['employee_first_name'] ?? null);
            $sameLastName = $this->sameNormalized($row->employee_last_name, $original['employee_last_name'] ?? null);
            $sameEmail = $this->sameNormalized($row->recipient_email, $original['recipient_email'] ?? null);

            if ($aiRecognizedSomething && $sameFirstName && $sameLastName && $sameEmail) {
                $matched++;
            }
        }

        if ($comparable === 0) {
            return ['key' => 'copilot.recipient_auto_matched', 'value' => '—', 'label' => 'Destinatari riconosciuti in automatico'];
        }

        return [
            'key' => 'copilot.recipient_auto_matched',
            'value' => $matched,
            'outOf' => $comparable,
            'label' => 'Destinatari riconosciuti in automatico',
        ];
    }

    /** Confronto senza distinzione di maiuscole/spazi iniziali o finali. */
    private function sameNormalized(?string $current, ?string $original): bool
    {
        $normalize = fn (?string $value): ?string => $value === null ? null : mb_strtolower(trim($value));

        return $normalize($current) === $normalize($original);
    }

    /**
     * Sotto il minuto e mezzo si legge in secondi, oltre in minuti con un decimale.
     *
     * @return array<string, mixed>
     */
    private function durationMetric(string $key, string $label, ?float $seconds): array
    {
        if ($seconds === null) {
            return ['key' => $key, 'value' => '—', 'label' => $label];
        }

        return $seconds < 90
            ? ['key' => $key, 'value' => (int) round($seconds), 'unit' => 's', 'label' => $label]
            : ['key' => $key, 'value' => number_format($seconds / 60, 1, '.', ''), 'unit' => 'min', 'label' => $label];
    }

    /**
     * Media con un decimale, o il segnaposto quando non c'e' nulla su cui farla:
     * uno zero verrebbe letto come una misura reale.
     */
    private function averageDecimal(mixed $average): string
    {
        return $average === null ? '—' : number_format((float) $average, 1, '.', '');
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
    public function copilotState(Actor $actor): array
    {
        $documents = SubDocument::query()
            ->with(['originalDocument', 'extractedData'])
            ->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))
            ->latest()
            ->limit(40)
            ->get();

        $documentsOfTenant = OriginalDocument::query()->where('tenant_id', $actor->tenantId);
        $originalCount = (clone $documentsOfTenant)->count();
        $confidenceThreshold = (int) config('services.bedrock.mvp_confidence_threshold', 80);

        $ofTenant = fn ($query) => $query->whereHas('originalDocument', fn ($documents) => $documents->where('tenant_id', $actor->tenantId));

        $subDocumentCount = $ofTenant(SubDocument::query())->count();
        $autoValidated = $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::AutoValidated)->count();
        $manuallyValidated = $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::ManuallyValidated)->count();
        $validated = $autoValidated + $manuallyValidated;
        // Un sotto-documento nasce a NeedsReview di default, prima ancora che
        // l'estrazione giri: `whereHas('extractedData')` esclude chi non e'
        // stato ancora valutato per davvero, non solo chi e' sotto soglia.
        $needsReview = $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::NeedsReview)->whereHas('extractedData')->count();
        $evaluatedCount = $ofTenant(SubDocument::query())->whereHas('extractedData')->count();
        $quarantined = $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::Quarantined)->count();

        return [
            'metrics' => [
                // Ordine pensato per riempire la griglia a quattro colonne senza
                // celle vuote (vedi copilot-page.view-model.ts); in fondo le
                // voci che il pannello non mostra, lette solo dalla Overview.
                [
                    'key' => 'copilot.documents',
                    'value' => $originalCount,
                    'label' => 'Documenti analizzati',
                    'history' => $this->dailySeries($documentsOfTenant),
                ],
                $this->averageDurationMetric(
                    'copilot.processing_seconds',
                    'Tempo medio di elaborazione',
                    $this->workflowDurations(OriginalDocument::query()->where('tenant_id', $actor->tenantId)),
                ),
                [
                    // Fuori dal pannello Co-Pilot (fuori da UC-56), ma resta nel
                    // contratto perche' la Overview la legge.
                    'key' => 'copilot.ocr_confidence',
                    'value' => $this->averageDecimal((clone $documentsOfTenant)->whereNotNull('ocr_confidence_avg')->avg('ocr_confidence_avg')),
                    'unit' => '%',
                    'threshold' => $confidenceThreshold,
                    'label' => 'Confidenza media OCR',
                ],
                [
                    // UC-56.2: denominatore $validated (auto + manuale), non
                    // $subDocumentCount — un sotto-documento ancora da
                    // revisionare non e' "classificato male", diluirebbe la
                    // quota con casi che non sono ne' successo ne' fallimento.
                    'key' => 'copilot.auto_classified',
                    'value' => $autoValidated,
                    'outOf' => $validated,
                    'label' => 'Classificazioni corrette senza intervento',
                ],
                [
                    // UC-56.3: denominatore $evaluatedCount, non $subDocumentCount
                    // — un sotto-documento non ancora estratto non e' "sopra
                    // soglia", e' senza una confidenza da confrontare.
                    'key' => 'copilot.needs_review',
                    'value' => $needsReview,
                    'outOf' => $evaluatedCount,
                    'label' => 'Sotto la soglia di confidenza',
                    'history' => $this->dailySeries($ofTenant(SubDocument::query())->where('review_status', ReviewStatus::NeedsReview)->whereHas('extractedData')),
                ],
                $this->recipientAutoMatchMetric($actor->tenantId),
                [
                    // Fuori dal pannello: e' il totale su cui si misurano le
                    // quote, e come conteggio a se' non aggiunge nulla.
                    'key' => 'copilot.sub_documents',
                    'value' => $subDocumentCount,
                    'label' => 'Sotto-documenti rilevati',
                    'history' => $this->dailySeries($ofTenant(SubDocument::query())),
                ],
                // Pronti = validati, automaticamente o a mano. Non e' il
                // complemento di "da verificare": la quarantena e' un terzo
                // stato che non va contato come pronto.
                [
                    'key' => 'copilot.validated',
                    'value' => $validated,
                    'label' => 'Documenti pronti',
                    'history' => $this->dailySeries($ofTenant(SubDocument::query())->whereIn('review_status', [ReviewStatus::AutoValidated, ReviewStatus::ManuallyValidated])),
                ],
                [
                    'key' => 'copilot.quarantined',
                    'value' => $quarantined,
                    'label' => 'In quarantena',
                    'history' => $this->dailySeries($ofTenant(SubDocument::query())->where('review_status', ReviewStatus::Quarantined)),
                ],
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
            'isFavorite' => (bool) $communication->is_favorite,
            'createdAt' => $communication->created_at?->format('d/m/Y H:i'),
            'rating' => $communication->rating,
            'ratingComment' => $communication->rating_comment,
            'ratedAt' => $communication->rated_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * URL relativo (vedi la nota in DocumentController::store sul mixed-content
     * dietro Traefik), con `v` derivato dalla chiave dell'oggetto: il percorso
     * non cambia quando la copertina viene sostituita, senza il browser
     * mostrerebbe quella vecchia finche' la cache non scade.
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
     * Campi la cui confidenza sta sotto la propria soglia (il codice fiscale ne
     * ha una piu' alta, identifica la persona — ADR 0013). Un campo senza
     * confidenza nota non entra nell'elenco: non rintracciato non e' letto male.
     *
     * @param  array<string, float|null>|null  $fieldConfidences
     * @return list<string>
     */
    private function lowConfidenceFields(?array $fieldConfidences): array
    {
        if ($fieldConfidences === null) {
            return [];
        }

        $threshold = (int) config('services.bedrock.mvp_confidence_threshold', 80);
        $fiscalCodeThreshold = (int) config('services.bedrock.mvp_fiscal_code_confidence_threshold', 95);

        $low = [];

        foreach ($fieldConfidences as $field => $confidence) {
            if ($confidence === null) {
                continue;
            }

            $applicable = $field === 'fiscal_code' ? $fiscalCodeThreshold : $threshold;

            if ((float) $confidence < $applicable) {
                $low[] = (string) $field;
            }
        }

        return $low;
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

        $sendMessage = $this->composeSendMessage($subDocument, $data);

        return [
            'id' => 'sub-'.$subDocument->id,
            'title' => $data?->document_type ?: $original?->original_filename,
            'employeeFirstName' => $data?->employee_first_name,
            'employeeLastName' => $data?->employee_last_name,
            'employee' => $employee !== '' ? $employee : null,
            'companyName' => $data?->company_name,
            'recipientEmail' => $data?->recipient_email,
            'uploadedAt' => $original?->created_at?->format('d/m/Y H:i'),
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
            'fieldConfidences' => $data?->field_confidences,
            'lowConfidenceFields' => $this->lowConfidenceFields($data?->field_confidences),
            'reviewStatus' => $subDocument->review_status->value,
            'reviewStatusLabel' => $subDocument->review_status->label(),
            'sendStatus' => $subDocument->send_status->value,
            'sendStatusLabel' => $subDocument->send_status->label(),
            'error' => $subDocument->error_message,
            // URL relativo: vedi nota in DocumentController::store. Un URL assoluto
            // sarebbe generato con schema "http://" dietro Traefik e bloccato dal
            // browser (mixed-content sull'iframe e sul fetch dell'anteprima).
            'previewUrl' => route('api.v1.documents.preview', ['subDocument' => $subDocument->id], false),
            // UC-40.2/RF56-OB: il documento originale completo, non splittato.
            'originalPreviewUrl' => route('api.v1.documents.original-preview', ['subDocument' => $subDocument->id], false),
            'sendRecipient' => $sendMessage['recipient'],
            'sendSubject' => $sendMessage['subject'],
            'sendBody' => $sendMessage['body'],
            'sendPreviewUrl' => route('api.v1.documents.send-preview', ['subDocument' => $subDocument->id], false),
            'sendExportUrl' => route('api.v1.documents.send-export', ['subDocument' => $subDocument->id], false),
            'previewLines' => $previewLines,
        ];
    }

    /**
     * L'anteprima del messaggio precompilato, con le stesse regole che ne
     * stampano il PDF: la bozza vive in {@see SendMessageDraft}, nel dominio.
     *
     * @return array{recipient: string, subject: string, body: string}
     */
    private function composeSendMessage(SubDocument $subDocument, ?ExtractedData $data): array
    {
        $employeeName = trim(($data?->employee_first_name ?? '').' '.($data?->employee_last_name ?? ''));
        $documentDate = $data?->document_date?->format('d/m/Y');
        $original = $subDocument->originalDocument;

        return [
            'recipient' => $subDocument->send_recipient_override
                ?: SendMessageDraft::recipient($employeeName),
            'subject' => $subDocument->send_subject_override
                ?: SendMessageDraft::subject(
                    $data?->document_type,
                    $data?->company_name,
                    $documentDate,
                    $original?->manual_reference_month,
                    $original?->manual_reference_year,
                ),
            'body' => $subDocument->send_body_override
                ?: SendMessageDraft::body(
                    $employeeName,
                    $data?->document_type,
                    $data?->company_name,
                    $documentDate,
                    $data?->description,
                ),
        ];
    }
}
