<?php

namespace App\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

class BedrockService
{
    public function __construct(
        private readonly BedrockRuntimeClient $client,
        private readonly string $modelId,
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
            } elseif (preg_match('/(\{.*\})/s', $text, $matches)) {
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
        $pdfContent = base64_encode(file_get_contents(storage_path('app/'.$pdfPath)));

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
        $pdfContent = base64_encode(file_get_contents(storage_path('app/'.$subPdfPath)));

        $prompt = "Estrai i seguenti campi da questo cedolino PDF.\nRispondi SOLO con JSON valido con le chiavi: employee_first_name, employee_last_name, company_name, document_date (formato YYYY-MM-DD), document_type, description (max 200 caratteri), confidence_score (intero 0-100 che indica la tua confidenza nell'estrazione).\nUsa null per i campi non trovati.";

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
}
