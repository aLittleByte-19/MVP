<?php

namespace App\Mvp\Observability;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;

/**
 * Legge la profondita' reale delle due DLQ di workflow a ogni scrape.
 *
 * Sostituisce un counter che non misurava la coda: `dlq_messages_total` veniva
 * incrementato solo quando una persona lanciava `php artisan mvp:dlq:list`, con
 * un tetto di 10 messaggi per esecuzione, ed essendo monotono non tornava mai
 * sotto zero. Gli alert critical DLQNotEmpty non potevano quindi scattare prima
 * della prima esecuzione manuale, e restavano appesi per sempre dopo.
 *
 * Su fallimento il probe restituisce null per quella coda: il chiamante non
 * emette alcuna serie di profondita' e segnala l'accaduto con dlq_probe_up a 0.
 * Emettere 0 sarebbe peggio del bug che stiamo correggendo — silenzierebbe un
 * alert critical facendo credere che la coda sia vuota.
 *
 * ADR 0010 tiene Observability fuori dal perimetro ports & adapters: classe
 * concreta che parla direttamente all'SDK AWS, senza porta intermedia.
 */
class DlqDepthProbe
{
    public function __construct(private readonly SqsClient $sqs) {}

    /**
     * Profondita' per coda. Una coda non configurata e' assente dal risultato
     * (non e' un errore: in molti ambienti la seconda pipeline non esiste),
     * una coda interrogata senza successo vale null.
     *
     * @return array<string, int|null>
     */
    public function depths(): array
    {
        if (! (bool) config('observability.metrics.dlq_probe_enabled', true)) {
            return [];
        }

        $queues = [
            'documents' => (string) config('services.workflow.dlq_queue_url'),
            'communications' => (string) config('services.workflow.communications_dlq_queue_url'),
        ];

        $depths = [];

        foreach ($queues as $pipeline => $queueUrl) {
            if ($queueUrl === '') {
                continue;
            }

            $depths[$pipeline] = $this->depth($queueUrl, $pipeline);
        }

        return $depths;
    }

    private function depth(string $queueUrl, string $pipeline): ?int
    {
        try {
            $result = $this->sqs->getQueueAttributes([
                'QueueUrl' => $queueUrl,
                'AttributeNames' => ['ApproximateNumberOfMessages'],
            ]);

            $attributes = $result->get('Attributes') ?? [];

            return (int) ($attributes['ApproximateNumberOfMessages'] ?? 0);
        } catch (\Throwable $e) {
            // Volutamente non rilanciata: uno scrape deve degradare, non fallire.
            // Il fatto che la misura manchi e' esposto da dlq_probe_up.
            Log::warning('DLQ depth probe failed', [
                'pipeline' => $pipeline,
                'message' => str($e->getMessage())->limit(220)->toString(),
            ]);

            return null;
        }
    }
}
