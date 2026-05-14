<?php

namespace App\Poc\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Aws\Result;
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
     * @return array{title: string, body: string}
     *
     * @throws \RuntimeException
     */
    public function generateCommunication(string $prompt, string $tone, string $style): array
    {
        if (! $this->enabled) {
            return $this->fallbackCommunication($prompt, $tone, $style);
        }

        $this->ensureConfigured();

        $aiPrompt = $this->buildCommunicationPrompt($prompt, $tone, $style);

        try {
            /** @var Result $response */
            $response = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $aiPrompt]]],
                ],
                'inferenceConfig' => ['maxTokens' => 2048, 'temperature' => 0.7],
            ]);

            $jsonResponse = $this->extractJsonFromAiResponse($response->toArray());

            if (! isset($jsonResponse['title'], $jsonResponse['body'])) {
                throw new \RuntimeException('Risposta Bedrock incompleta: chiavi title/body mancanti.');
            }

            return [
                'title' => (string) $jsonResponse['title'],
                'body' => (string) $jsonResponse['body'],
            ];
        } catch (AwsException $e) {
            Log::error('AI Generation Error', ['error' => $e->getMessage()]);
            throw new \RuntimeException("Errore di connessione con Bedrock: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Bedrock responses may wrap JSON in markdown fences or short prose.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array<string, mixed>
     */
    private function extractJsonFromAiResponse(array $rawResponse): array
    {
        $text = $rawResponse['output']['message']['content'][0]['text'] ?? '';

        // Strip optional ```json fences before decoding.
        $cleanJson = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($text));

        // If the model adds prose, isolate the first JSON object or array.
        if (! str_starts_with($cleanJson, '{') && ! str_starts_with($cleanJson, '[')) {
            preg_match('/([\{\[].*[\}\]])/s', $cleanJson, $matches);
            $cleanJson = $matches[1] ?? $cleanJson;
        }

        $decoded = json_decode($cleanJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{title: string, body: string}
     */
    private function fallbackCommunication(string $prompt, string $tone, string $style): array
    {
        return [
            'title' => 'Bozza NEXUM (Simulata)',
            'body' => "LOGICA POC: Generazione disabilitata.\nPrompt: {$prompt}\nTono: {$tone}\nStile: {$style}",
        ];
    }

    private function buildCommunicationPrompt(string $userPrompt, string $tone, string $style): string
    {
        return "Agisci come un assistente HR. Genera una comunicazione con tono '{$tone}' e stile '{$style}'.\n"
             ."Argomento: {$userPrompt}\n"
             .'Rispondi esclusivamente in formato JSON: {"title": "...", "body": "..."}';
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

        $pdfContent = Storage::disk($this->documentDisk())->get($pdfPath);

        if ($pdfContent === null) {
            throw new \RuntimeException("File non trovato sul disco: {$pdfPath}");
        }

        $prompt = "Analizza questo PDF di cedolini aziendali. Identifica tutti i dipendenti presenti.\nPer ogni dipendente restituisci un array JSON con: employee_name (stringa), start_page (intero, 1-indexed), end_page (intero, 1-indexed).\nRispondi SOLO con JSON valido (array). Se non ci sono dipendenti distinti, restituisci un array vuoto.";

        try {
            /** @var Result $result */
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

            $decoded = $this->extractJsonFromAiResponse($result->toArray());

            return is_array($decoded) ? $decoded : [];
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

        $pdfContent = Storage::disk($this->documentDisk())->get($subPdfPath);

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

            $decoded = $this->extractJsonFromAiResponse($result->toArray());

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

    /**
     * @throws \RuntimeException when Bedrock is enabled without the required client/model config.
     */
    private function ensureConfigured(): void
    {
        if (! $this->client || ! $this->modelId) {
            throw new \RuntimeException('Bedrock non configurato: impostare BEDROCK_ENABLED=true e BEDROCK_MODEL_ID.');
        }
    }

    public static function formatUserError(\Throwable $e, string $fallback): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'expiredtoken')) {
            return 'Le credenziali AWS temporanee sono scadute. Aggiorna AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY e AWS_SESSION_TOKEN nel pannello admin.';
        }

        if (str_contains($message, 'model access is denied')) {
            return 'Il modello Bedrock configurato non è accessibile con queste credenziali. Usa un modello abilitato (es. amazon.nova-lite-v1:0).';
        }

        if (str_contains($message, 'on-demand throughput') || str_contains($message, 'inference profile')) {
            return 'Il modello Bedrock richiede un inference profile. Aggiorna BEDROCK_MODEL_ID nel pannello admin.';
        }

        return $fallback;
    }

    private function documentDisk(): string
    {
        return config('filesystems.default', 'local');
    }
}
