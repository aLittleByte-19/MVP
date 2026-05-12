<?php

namespace App\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BedrockService
{
    public function __construct(
        private readonly ?BedrockRuntimeClient $client,
        private readonly ?string $modelId,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Generate a communication title and body from a prompt.
     *
     * @return array{title: string, body: string}
     *
     * @throws \RuntimeException
     */
    public function generateCommunication(string $prompt, string $tone, string $style): array
    {
        if (! $this->enabled) {
            return [
                'title' => 'Bozza comunicazione NEXUM',
                'body' => "Contenuto generato in modalita PoC con tono {$tone} e stile {$style}. Prompt di partenza: {$prompt}",
            ];
        }

        $this->ensureConfigured();

        $userMessage = "Genera una comunicazione aziendale con tono '{$tone}' e stile '{$style}'.\n\nContenuto richiesto: {$prompt}\n\nRispondi SOLO con JSON valido con le chiavi: title (stringa), body (stringa).";

        try {
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $userMessage]]],
                ],
                'inferenceConfig' => ['maxTokens' => 2048, 'temperature' => 0.7],
            ]);

            $text = $result['output']['message']['content'][0]['text'] ?? '';

            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches)) {
                $text = $matches[1];
            } elseif (preg_match('/(\{.*?\})/s', $text, $matches)) {
                $text = $matches[1];
            }

            $decoded = json_decode($text, true);

            if (! is_array($decoded) || ! isset($decoded['title'], $decoded['body'])) {
                throw new \RuntimeException('Risposta Bedrock non valida: JSON mancante o malformato.');
            }

            return ['title' => $decoded['title'], 'body' => $decoded['body']];
        } catch (AwsException $e) {
            Log::error('Bedrock generateCommunication error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Analyse a multi-employee PDF and return per-employee page boundaries.
     *
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     *
     * @throws \RuntimeException
     */
    public function splitDocument(string $pdfPath): array
    {
        if (! $this->enabled) {
            return [
                ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 1],
            ];
        }

        $this->ensureConfigured();

        $pdfContent = Storage::disk('local')->get($pdfPath);

        if ($pdfContent === null) {
            throw new \RuntimeException("File non trovato sul disco: {$pdfPath}");
        }

        $prompt = "Analizza questo PDF di cedolini aziendali. Identifica tutti i dipendenti presenti.\nPer ogni dipendente restituisci un array JSON con: employee_name (stringa), start_page (intero, 1-indexed), end_page (intero, 1-indexed).\nRispondi SOLO con JSON valido (array). Se non ci sono dipendenti distinti, restituisci un array vuoto.";

        try {
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'document' => [
                                    'name' => 'cedolini',
                                    'format' => 'pdf',
                                    'source' => ['bytes' => $pdfContent],
                                ],
                            ],
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'inferenceConfig' => ['maxTokens' => 1024, 'temperature' => 0.1],
            ]);

            $text = $result['output']['message']['content'][0]['text'] ?? '[]';

            if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $text, $matches)) {
                $text = $matches[1];
            } elseif (preg_match('/(\[.*?\])/s', $text, $matches)) {
                $text = $matches[1];
            }

            $decoded = json_decode($text, true);

            if (! is_array($decoded)) {
                throw new \RuntimeException('Bedrock splitDocument: risposta non è un array JSON valido.');
            }

            return $decoded;
        } catch (AwsException $e) {
            Log::error('Bedrock splitDocument error', ['path' => $pdfPath, 'message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock (split): '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Extract structured fields from a single-employee sub-document PDF.
     *
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     *
     * @throws \RuntimeException
     */
    public function extractFields(string $subPdfPath): array
    {
        if (! $this->enabled) {
            return [
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Azienda Demo Srl',
                'document_date' => now()->toDateString(),
                'document_type' => 'Cedolino',
                'description' => 'Dati estratti in modalita PoC.',
                'confidence_score' => (int) config('services.bedrock.poc_confidence_threshold', 80),
            ];
        }

        $this->ensureConfigured();

        $pdfContent = Storage::disk('local')->get($subPdfPath);

        if ($pdfContent === null) {
            throw new \RuntimeException("File non trovato sul disco: {$subPdfPath}");
        }

        $prompt = "Estrai i seguenti campi da questo cedolino PDF.\nRispondi SOLO con JSON valido con le chiavi: employee_first_name, employee_last_name, company_name, document_date (formato YYYY-MM-DD), document_type, description (max 200 caratteri), confidence_score (intero 0-100).\nUsa null per i campi non trovati.\n\nPer confidence_score usa questa scala:\n- 90-100: tutti i campi principali (nome, cognome, azienda, data) sono chiaramente leggibili\n- 70-89: la maggior parte dei campi è leggibile ma uno o due sono ambigui o parziali\n- 40-69: diversi campi mancanti o incerti, testo poco chiaro o layout non standard\n- 0-39: documento illeggibile, non è un cedolino, o quasi tutti i campi sono assenti";

        try {
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'document' => [
                                    'name' => 'sub_document',
                                    'format' => 'pdf',
                                    'source' => ['bytes' => $pdfContent],
                                ],
                            ],
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'inferenceConfig' => ['maxTokens' => 512, 'temperature' => 0.1],
            ]);

            $text = $result['output']['message']['content'][0]['text'] ?? '{}';

            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches)) {
                $text = $matches[1];
            } elseif (preg_match('/(\{.*?\})/s', $text, $matches)) {
                $text = $matches[1];
            }

            $decoded = json_decode($text, true);

            if (! is_array($decoded)) {
                throw new \RuntimeException('Bedrock extractFields: risposta non è un oggetto JSON valido.');
            }

            return [
                'employee_first_name' => $decoded['employee_first_name'] ?? null,
                'employee_last_name' => $decoded['employee_last_name'] ?? null,
                'company_name' => $decoded['company_name'] ?? null,
                'document_date' => $decoded['document_date'] ?? null,
                'document_type' => $decoded['document_type'] ?? null,
                'description' => $decoded['description'] ?? null,
                'confidence_score' => isset($decoded['confidence_score']) ? (int) $decoded['confidence_score'] : null,
            ];
        } catch (AwsException $e) {
            Log::error('Bedrock extractFields error', ['path' => $subPdfPath, 'message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock (extract): '.$e->getMessage(), previous: $e);
        }
    }

    private function ensureConfigured(): void
    {
        if (! $this->client || ! $this->modelId) {
            throw new \RuntimeException('Bedrock non configurato: impostare BEDROCK_ENABLED=true e BEDROCK_MODEL_ID.');
        }
    }
}
