<?php

namespace App\Mvp\Support;

use App\Models\Communication;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\PromptConfiguration;
use App\Models\SubDocument;
use App\Models\WorkflowTask;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Support\Identity\Actor;
use Illuminate\Database\Eloquent\Builder;

class MvpStateService
{
    /**
     * Giorni coperti dalla serie storica delle metriche.
     */
    private const HISTORY_DAYS = 7;

    /**
     * Le fasi della pipeline documentale, nell'ordine in cui l'elaborazione le
     * attraversa, con l'etichetta con cui compaiono nella ripartizione. I nomi
     * a sinistra sono i `task_type` scritti da WorkflowTaskRunner.
     *
     * `dispatch.domain_event` non compare: e' la notifica interna che segue il
     * lavoro, dura millesimi di secondo e nella barra sarebbe un segmento
     * invisibile con un'etichetta ingombrante.
     */
    private const DOCUMENT_PHASES = [
        'textract.ocr' => 'OCR',
        'bedrock.extract' => 'Estrazione',
        'persist.results' => 'Salvataggio',
    ];

    /** Come sopra, per la pipeline delle comunicazioni. */
    private const COMMUNICATION_PHASES = [
        'communication.generate_text' => 'Testo',
        'communication.generate_cover' => 'Copertina',
        'communication.finalize' => 'Chiusura',
    ];

    /**
     * I campi che l'estrazione puo' valorizzare. Sono il denominatore di
     * "campi compilati": quanta parte della scheda il modello riesce a
     * riempire da sola.
     */
    private const EXTRACTED_FIELDS = [
        'employee_first_name',
        'employee_last_name',
        'company_name',
        'document_date',
        'document_type',
        'description',
        'recipient_email',
        'fiscal_code',
        'employee_id',
    ];

    /**
     * Passi ammessi per l'asse dei tempi della densita'. Dieci intervalli di
     * uno di questi valori coprono la durata piu' lunga: cosi' le tacche
     * cadono su numeri che si leggono (30s, 60s) invece che su 17,3s.
     *
     * @var list<int>
     */
    private const DURATION_STEPS = [1, 2, 5, 10, 15, 20, 30, 60, 120, 300, 600];

    /** Intervalli in cui si divide l'asse della densita'. */
    private const DURATION_BUCKETS = 10;

    /**
     * Sotto questa soglia una curva di densita' e' piu' interpolazione che
     * dato: l'interfaccia la sostituisce con una riga di testo, e per farlo
     * deve sapere quante misure ci sono dietro.
     */
    private const DENSITY_MIN_SAMPLES = 8;

    /**
     * @return array<string, mixed>
     */
    public function forActor(Actor $actor): array
    {
        return [
            'assistant' => $this->assistantState($actor),
            'copilot' => $this->copilotState($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantState(Actor $actor): array
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
                // L'ordine e' quello in cui le schede riempiono il mosaico del
                // pannello: prima le quattro strette, che si leggono in un colpo
                // d'occhio, poi le larghe a coppie. Cambiarlo lascia righe spaiate
                // nella griglia a quattro colonne.
                [
                    'key' => 'assistant.total',
                    'value' => $total,
                    'label' => 'Contenuti generati',
                    'history' => $this->dailySeries(Communication::query()->where('tenant_id', $actor->tenantId)),
                ],
                [
                    'key' => 'assistant.generation_failed',
                    'value' => (clone $baseQuery)->where('generation_status', CommunicationGenerationStatus::Failed)->count(),
                    'label' => 'Generazioni non riuscite',
                    'history' => $this->dailySeries(
                        Communication::query()->where('tenant_id', $actor->tenantId)->where('generation_status', CommunicationGenerationStatus::Failed)
                    ),
                ],
                [
                    'key' => 'assistant.generation_stuck',
                    'value' => (clone $baseQuery)
                        ->where('generation_status', CommunicationGenerationStatus::Processing)
                        ->where('workflow_started_at', '<', now()->subSeconds($this->generationTimeoutSeconds()))
                        ->count(),
                    'label' => 'Oltre il tempo previsto',
                ],
                [
                    'key' => 'assistant.covers_failed',
                    'value' => (clone $baseQuery)->where('cover_status', CoverImageStatus::Failed)->count(),
                    'label' => 'Copertine non riuscite',
                ],
                [
                    'key' => 'assistant.rated',
                    'value' => $rated,
                    'outOf' => $total,
                    'label' => 'Bozze valutate',
                ],
                [
                    // Nessuna serie: e' una media, non un conteggio di elementi
                    // entrati, quindi un flusso giornaliero non la descrive.
                    'key' => 'assistant.rating_average',
                    'value' => $averageRating === null ? '—' : number_format((float) $averageRating, 1, '.', ''),
                    'unit' => '/ 5',
                    'sampleSize' => $rated,
                    'label' => 'Media stelle',
                ],
                $this->phaseMetric(
                    'assistant.generation_seconds',
                    'Tempo medio di generazione',
                    'communication',
                    Communication::query()->where('tenant_id', $actor->tenantId)->select('id'),
                    self::COMMUNICATION_PHASES,
                    $this->workflowDurations(Communication::query()->where('tenant_id', $actor->tenantId)),
                ),
                $this->distributionMetric(
                    'assistant.duration',
                    'Durata delle generazioni',
                    $this->workflowDurations(Communication::query()->where('tenant_id', $actor->tenantId)),
                ),
                [
                    // Non compare nel pannello: e' la parte "in bozza" della
                    // ripartizione e, come priorita', vive nella Overview.
                    'key' => 'assistant.drafts',
                    'value' => $drafts,
                    'label' => 'Bozze generate',
                    'history' => $this->dailySeries(
                        Communication::query()->where('tenant_id', $actor->tenantId)->where('status', CommunicationStatus::Draft)
                    ),
                ],
            ],
            'history' => $history->map(fn ($communication) => $this->communication($communication))->values()->all(),
            'promptConfigurations' => $promptConfigurations->map(fn ($configuration) => $this->promptConfiguration($configuration))->values()->all(),
        ];
    }

    /**
     * Conteggio giornaliero degli ultimi sette giorni, dal piu' vecchio al piu'
     * recente e con gli zeri espliciti sui giorni senza elementi.
     *
     * E' un **flusso di ingresso**, non la storia dello stock: dice quanti
     * elementi sono *entrati* in quello stato ogni giorno, non come il totale
     * e' variato. Ricostruire lo stock richiederebbe snapshot giornalieri, che
     * il modello dati non conserva. La distinzione va mantenuta anche nella UI:
     * accanto a "23 in attesa" si legge "3 nuovi oggi", mai "+3 rispetto a ieri".
     *
     * Il raggruppamento avviene in PHP invece che con una funzione di data SQL
     * perche' deve valere su PostgreSQL e su SQLite (suite di test) senza
     * dialetti diversi; i volumi di una finestra di sette giorni lo consentono.
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
     * Durata media in secondi delle corse concluse negli ultimi sette giorni.
     *
     * La finestra e' la stessa della serie storica: una media su tutto lo
     * storico descriverebbe un sistema che non e' piu' quello in esercizio, e
     * una corsa lenta di mesi fa peserebbe quanto una di stamattina.
     *
     * La differenza fra i due istanti si calcola in PHP e non con una funzione
     * di data SQL, per la stessa ragione di `dailySeries`: deve valere su
     * PostgreSQL e su SQLite senza dialetti diversi.
     *
     * @param  Builder<OriginalDocument>|Builder<Communication>  $query
     */
    private function averageWorkflowSeconds(Builder $query): ?float
    {
        $since = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();

        $durations = (clone $query)
            ->whereNotNull('workflow_started_at')
            ->whereNotNull('workflow_completed_at')
            ->where('workflow_completed_at', '>=', $since)
            ->get(['workflow_started_at', 'workflow_completed_at'])
            ->map(fn ($row): float => (float) $row->workflow_started_at->diffInSeconds($row->workflow_completed_at, true));

        return $durations->isEmpty() ? null : (float) $durations->avg();
    }

    /**
     * Durate in secondi delle corse concluse negli ultimi sette giorni.
     *
     * La differenza fra i due istanti si calcola in PHP e non con una funzione
     * di data SQL, per la stessa ragione di `dailySeries`: deve valere su
     * PostgreSQL e su SQLite senza dialetti diversi.
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
     * Densita' delle durate: quante elaborazioni cadono in ciascun intervallo.
     *
     * L'asse arriva alla corsa piu' lunga, non al novantacinquesimo percentile:
     * troncare la coda nasconderebbe proprio il caso che l'operatore cerca.
     * Il valore della metrica resta la mediana, che serve alla descrizione
     * accessibile e al testo di ripiego quando i campioni sono pochi.
     *
     * @param  list<float>  $durations
     * @return array<string, mixed>
     */
    private function distributionMetric(string $key, string $label, array $durations): array
    {
        $sampleSize = count($durations);

        if ($sampleSize === 0) {
            return ['key' => $key, 'value' => '—', 'sampleSize' => 0, 'label' => $label];
        }

        sort($durations);
        $median = $durations[intdiv($sampleSize, 2)];
        $step = $this->durationStep(max($durations));
        $distribution = [];

        for ($bucket = 1; $bucket <= self::DURATION_BUCKETS; $bucket++) {
            $upTo = $step * $bucket;
            $from = $upTo - $step;
            $distribution[] = [
                'upTo' => $upTo,
                'count' => count(array_filter(
                    $durations,
                    fn (float $seconds): bool => $seconds > $from && $seconds <= $upTo || ($from === 0 && $seconds === 0.0)
                )),
            ];
        }

        return [
            'key' => $key,
            'value' => (int) round($median),
            'unit' => 's',
            'sampleSize' => $sampleSize,
            'distribution' => $distribution,
            'label' => $label,
        ];
    }

    /** Il passo piu' stretto i cui dieci intervalli coprono la corsa piu' lunga. */
    private function durationStep(float $longest): int
    {
        foreach (self::DURATION_STEPS as $step) {
            if ($step * self::DURATION_BUCKETS >= $longest) {
                return $step;
            }
        }

        return (int) ceil($longest / self::DURATION_BUCKETS);
    }

    /**
     * Tempo medio di una corsa, ripartito fra le fasi che l'hanno consumato.
     *
     * Le fasi arrivano da `workflow_tasks`, che tiene inizio e fine di ogni
     * passo; il tenant si filtra sui soggetti, perche' la tabella e' condivisa
     * fra le due pipeline e non porta il tenant per se'. L'ultima voce e'
     * l'attesa: la differenza fra la durata complessiva e la somma delle fasi,
     * cioe' il tempo passato in coda fra un passo e l'altro. Senza, la barra
     * direbbe che la corsa e' finita prima di quanto sia vero.
     *
     * @param  Builder<OriginalDocument>|Builder<Communication>  $subjects
     * @param  array<string, string>  $phases
     * @param  list<float>  $durations
     * @return array<string, mixed>
     */
    private function phaseMetric(string $key, string $label, string $subjectType, Builder $subjects, array $phases, array $durations): array
    {
        $average = $durations === [] ? null : array_sum($durations) / count($durations);

        if ($average === null) {
            return ['key' => $key, 'value' => '—', 'label' => $label];
        }

        $since = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();
        $byPhase = WorkflowTask::query()
            ->where('subject_type', $subjectType)
            ->whereIn('task_type', array_keys($phases))
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->whereIn('subject_id', $subjects)
            ->get(['task_type', 'started_at', 'completed_at'])
            ->groupBy('task_type')
            ->map(fn ($tasks): float => (float) $tasks
                ->map(fn ($task): float => (float) $task->started_at->diffInSeconds($task->completed_at, true))
                ->avg());

        $parts = [];

        foreach ($phases as $taskType => $phaseLabel) {
            $seconds = $byPhase[$taskType] ?? null;

            if ($seconds !== null && $seconds > 0) {
                $parts[] = ['label' => $phaseLabel, 'value' => round($seconds, 1)];
            }
        }

        $waiting = round($average - array_sum(array_column($parts, 'value')), 1);

        if ($parts !== [] && $waiting > 0) {
            $parts[] = ['label' => 'Attesa', 'value' => $waiting];
        }

        // Con la ripartizione il totale resta in secondi anche oltre il minuto
        // e mezzo: le parti sono in secondi, e "1,7 min" sopra una legenda che
        // dice "OCR 60s" costringerebbe a convertire per verificare la somma.
        if ($parts !== []) {
            return [
                'key' => $key,
                'value' => (int) round($average),
                'unit' => 's',
                'parts' => $parts,
                'label' => $label,
            ];
        }

        return $this->durationMetric($key, $label, $average);
    }

    /**
     * Quanta parte della scheda il modello riesce a riempire da solo.
     *
     * Il denominatore sono i nove campi estraibili per ogni sotto-documento,
     * non i soli quattro principali: un codice fiscale mancante e' un campo che
     * l'operatore dovra' scrivere a mano, e va contato come tale.
     *
     * @return array<string, mixed>
     */
    private function filledFieldsMetric(string $tenantId, int $subDocumentCount): array
    {
        $rows = ExtractedData::query()
            ->whereHas('subDocument.originalDocument', fn ($query) => $query->where('tenant_id', $tenantId))
            ->get(self::EXTRACTED_FIELDS);

        $filled = 0;

        foreach ($rows as $row) {
            foreach (self::EXTRACTED_FIELDS as $field) {
                $value = $row->getAttribute($field);

                if ($value !== null && trim((string) $value) !== '') {
                    $filled++;
                }
            }
        }

        return [
            'key' => 'copilot.fields_filled',
            'value' => $filled,
            'outOf' => $subDocumentCount * count(self::EXTRACTED_FIELDS),
            'label' => "Campi compilati dall'AI",
        ];
    }

    /**
     * Scheda di una durata media: sotto il minuto e mezzo si legge in secondi,
     * oltre in minuti con un decimale. Un "312 s" e' un numero che va convertito
     * a mente, e un "0,4 min" e' una precisione che la misura non ha.
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
     * Oltre questa eta' una generazione ancora in corso e' considerata bloccata.
     * Stessa soglia del gauge `mvp_communications_stuck_processing`.
     */
    private function generationTimeoutSeconds(): int
    {
        return (int) config('mvp.communications.generation_timeout_seconds', 900);
    }

    /** Come sopra, per la pipeline documentale (`mvp_documents_stuck_processing`). */
    private function processingTimeoutSeconds(): int
    {
        return (int) config('mvp.document_limits.processing_timeout_seconds', 1800);
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
        $validated = $ofTenant(SubDocument::query())->whereIn('review_status', [ReviewStatus::AutoValidated, ReviewStatus::ManuallyValidated])->count();
        $needsReview = $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::NeedsReview)->count();
        $quarantined = $ofTenant(SubDocument::query())->where('review_status', ReviewStatus::Quarantined)->count();

        return [
            'metrics' => [
                // L'ordine e' quello in cui le schede riempiono il mosaico del
                // pannello: prima le quattro strette, poi le larghe a coppie.
                // In fondo le quattro che il pannello non mostra — il totale e le
                // parti della ripartizione — che restano nel contratto perche' la
                // Overview e la barra degli esiti le leggono.
                [
                    'key' => 'copilot.documents',
                    'value' => $originalCount,
                    'label' => 'Documenti analizzati',
                    'history' => $this->dailySeries($documentsOfTenant),
                ],
                [
                    // Media delle confidenze OCR dichiarate da Textract: dice
                    // quanto e' leggibile cio' che arriva, e la soglia accanto
                    // dice oltre quale valore il sistema valida da solo.
                    'key' => 'copilot.ocr_confidence',
                    'value' => $this->averageDecimal((clone $documentsOfTenant)->whereNotNull('ocr_confidence_avg')->avg('ocr_confidence_avg')),
                    'unit' => '%',
                    'threshold' => $confidenceThreshold,
                    'label' => 'Confidenza media OCR',
                ],
                [
                    'key' => 'copilot.processing_failed',
                    'value' => (clone $documentsOfTenant)->where('processing_status', ProcessingStatus::Failed)->count(),
                    'label' => 'Elaborazioni non riuscite',
                    'history' => $this->dailySeries(
                        OriginalDocument::query()->where('tenant_id', $actor->tenantId)->where('processing_status', ProcessingStatus::Failed)
                    ),
                ],
                [
                    'key' => 'copilot.processing_stuck',
                    'value' => (clone $documentsOfTenant)
                        ->where('processing_status', ProcessingStatus::Processing)
                        ->where('workflow_started_at', '<', now()->subSeconds($this->processingTimeoutSeconds()))
                        ->count(),
                    'label' => 'Oltre il tempo previsto',
                ],
                [
                    'key' => 'copilot.verified',
                    'value' => $validated,
                    'outOf' => $subDocumentCount,
                    'label' => 'Sotto-documenti verificati',
                ],
                $this->filledFieldsMetric($actor->tenantId, $subDocumentCount),
                $this->phaseMetric(
                    'copilot.processing_seconds',
                    'Tempo medio di elaborazione',
                    'original_document',
                    OriginalDocument::query()->where('tenant_id', $actor->tenantId)->select('id'),
                    self::DOCUMENT_PHASES,
                    $this->workflowDurations(OriginalDocument::query()->where('tenant_id', $actor->tenantId)),
                ),
                $this->distributionMetric(
                    'copilot.duration',
                    'Durata delle elaborazioni',
                    $this->workflowDurations(OriginalDocument::query()->where('tenant_id', $actor->tenantId)),
                ),
                [
                    // Fuori dal pannello: e' il totale su cui si misurano le
                    // quote, e come conteggio a se' non aggiunge nulla.
                    'key' => 'copilot.sub_documents',
                    'value' => $subDocumentCount,
                    'label' => 'Sotto-documenti rilevati',
                    'history' => $this->dailySeries($ofTenant(SubDocument::query())),
                ],
                [
                    'key' => 'copilot.needs_review',
                    'value' => $needsReview,
                    'label' => 'Da verificare',
                    'history' => $this->dailySeries($ofTenant(SubDocument::query())->where('review_status', ReviewStatus::NeedsReview)),
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

    /**
     * Compone destinatario/oggetto/testo del messaggio di invio precompilato
     * (UC-48 e derivati) dai dati gia' estratti: nessuna generazione AI,
     * calcolato al volo a ogni richiesta a meno che l'operatore non abbia
     * corretto uno dei campi, nel qual caso vince l'override persistito su
     * `sub_documents`. Duplica volutamente la stessa logica di
     * `SendMessageService::compose()` nel dominio Documents: qui opera su un
     * model Eloquent gia' caricato (MvpStateService resta infrastruttura di
     * lettura condivisa, fuori dal perimetro esagonale, vedi ADR 0010), la'
     * su un value object di dominio — condividerla accoppierebbe due livelli
     * architetturali diversi per risparmiare una manciata di righe.
     *
     * @return array{recipient: string, subject: string, body: string}
     */
    private function composeSendMessage(SubDocument $subDocument, ?ExtractedData $data): array
    {
        $employeeName = trim(($data?->employee_first_name ?? '').' '.($data?->employee_last_name ?? ''));
        $documentType = $data?->document_type;
        $companyName = $data?->company_name;
        $documentDate = $data?->document_date?->format('d/m/Y');

        return [
            'recipient' => $subDocument->send_recipient_override
                ?: ($employeeName !== '' ? $employeeName : 'Destinatario non disponibile'),
            'subject' => $subDocument->send_subject_override
                ?: ($documentType ? "Invio documento — {$documentType}" : 'Invio documento'),
            'body' => $subDocument->send_body_override
                ?: $this->composeSendMessageBody($employeeName, $documentType, $companyName, $documentDate, $data?->description),
        ];
    }

    private function composeSendMessageBody(string $employeeName, ?string $documentType, ?string $companyName, ?string $documentDate, ?string $description): string
    {
        $greeting = $employeeName !== '' ? "Gentile {$employeeName}," : 'Gentile destinatario,';
        $documentLabel = $documentType ?: 'documento';
        $reference = "in allegato trova il documento \"{$documentLabel}\"";

        if ($companyName) {
            $reference .= " relativo a {$companyName}";
        }

        if ($documentDate) {
            $reference .= " del {$documentDate}";
        }

        $reference .= '.';

        $lines = [$greeting, '', $reference];

        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = 'Cordiali saluti.';

        return implode("\n", $lines);
    }
}
