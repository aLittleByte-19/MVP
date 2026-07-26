<?php

namespace App\Copilot\Ai;

use App\Exceptions\Copilot\InvalidAiOutputException;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Aws\Result;
use Illuminate\Support\Facades\Log;

class BedrockService
{
    /** @var array<int, array{width: int, height: int}> */
    private const NOVA_CANVAS_SIZE_CANDIDATES = [
        ['width' => 1280, 'height' => 720],
        ['width' => 1024, 'height' => 1024],
        ['width' => 720, 'height' => 1280],
    ];

    private const NOVA_CANVAS_MAX_PROMPT_LENGTH = 1000;

    private const STABILITY_MAX_PROMPT_LENGTH = 1000;

    public function __construct(
        private readonly BedrockRuntimeClient $client,
        private readonly ?string $modelId,
        private readonly ?string $imageModelId,
        private readonly AiOutputValidator $validator,
    ) {}

    /**
     * @return array{title: string, body: string}
     *
     * @throws \RuntimeException
     */
    public function generateCommunication(string $prompt, string $tone, string $style): array
    {
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

            $jsonResponse = $this->extractJsonFromAiResponse($response->toArray(), 'generateCommunication');

            return $this->validator->validateGenerateCommunication($jsonResponse);
        } catch (AwsException $e) {
            Log::error('AI Generation Error', ['error' => $e->getMessage()]);
            throw new \RuntimeException("Errore di connessione con Bedrock: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate a visual cover for the drafted communication.
     * Returns a data URL when an image model is configured and reachable.
     */
    public function generateCommunicationImage(string $prompt, string $tone, string $style): ?string
    {
        return $this->generateCommunicationImageWithMeta($prompt, $tone, $style)['image'];
    }

    /**
     * Generate a visual cover and expose a user-facing warning when unavailable.
     *
     * @return array{image: ?string, warning: ?string}
     */
    public function generateCommunicationImageWithMeta(string $prompt, string $tone, string $style): array
    {
        if (! $this->imageModelId) {
            return [
                'image' => null,
                'warning' => 'Copertina AI non disponibile: modello immagini Bedrock non configurato.',
            ];
        }

        $imagePrompt = $this->buildCommunicationImagePrompt($prompt, $tone, $style);
        $safeImagePrompt = $this->buildSafeFallbackImagePrompt($prompt, $tone, $style);
        $usingSafePrompt = false;
        $warning = null;

        foreach (self::NOVA_CANVAS_SIZE_CANDIDATES as $size) {
            try {
                /** @var Result $result */
                $result = $this->client->invokeModel([
                    'modelId' => $this->imageModelId,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(
                        $this->buildImageInvokePayload($imagePrompt, $size),
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                $body = $this->decodeInvokeModelBody($result->get('body'));
                $decoded = json_decode($body, true);

                if (! is_array($decoded)) {
                    throw new \RuntimeException('Risposta immagine non decodificabile.');
                }

                $imageDataUrl = $this->extractImageDataUrl($decoded);
                if ($imageDataUrl !== null) {
                    return ['image' => $imageDataUrl, 'warning' => null];
                }

                $responseWarning = $this->classifyImageResponseWarning($decoded);

                Log::warning('Bedrock image model returned no image payload', [
                    'model' => $this->imageModelId,
                    'size' => $size,
                    'keys' => array_keys($decoded),
                    'finish_reasons' => $decoded['finish_reasons'] ?? null,
                ]);

                if ($this->hasPromptFilterFinishReason($decoded) && ! $usingSafePrompt) {
                    $usingSafePrompt = true;
                    $imagePrompt = $safeImagePrompt;

                    Log::info('Retrying Bedrock image generation with sanitized prompt after filter reason.', [
                        'model' => $this->imageModelId,
                        'size' => $size,
                    ]);

                    continue;
                }

                if ($this->hasPromptFilterFinishReason($decoded) && $usingSafePrompt) {
                    $warning ??= 'Copertina AI non disponibile: la richiesta immagini è stata bloccata dai controlli di sicurezza del modello Stability.';
                    continue;
                }

                if ($responseWarning['warning'] !== null) {
                    $warning ??= $responseWarning['warning'];

                    if (! $responseWarning['retryable']) {
                        return ['image' => null, 'warning' => $warning];
                    }
                }

                $warning ??= 'Copertina AI non disponibile: il modello non ha restituito un payload immagine valido.';
            } catch (AwsException $e) {
                $warning ??= $this->formatImageWarningFromError($e->getMessage());
                Log::warning('Bedrock communication image generation failed', [
                    'message' => $e->getMessage(),
                    'size' => $size,
                ]);
            } catch (\Throwable $e) {
                $warning ??= 'Copertina AI non disponibile per un errore temporaneo del servizio immagini.';
                Log::warning('Communication image parsing failed', [
                    'message' => $e->getMessage(),
                    'size' => $size,
                ]);
            }
        }

        return [
            'image' => null,
            'warning' => $warning ?? 'Copertina AI non disponibile al momento.',
        ];
    }

    /**
     * Bedrock responses may wrap JSON in markdown fences or short prose.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array<int|string, mixed>
     *
     * @throws InvalidAiOutputException when the response text is not decodable JSON.
     */
    private function extractJsonFromAiResponse(array $rawResponse, string $operation): array
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

        if (! is_array($decoded)) {
            throw new InvalidAiOutputException($operation, ['la risposta del modello non è JSON decodificabile']);
        }

        return $decoded;
    }

    private function buildCommunicationPrompt(string $userPrompt, string $tone, string $style): string
    {
        return "Agisci come un assistente HR. Genera una comunicazione con tono '{$tone}' e stile '{$style}'.\n"
             ."Argomento: {$userPrompt}\n"
             .'Rispondi esclusivamente in formato JSON: {"title": "...", "body": "..."}';
    }

    private function buildCommunicationImagePrompt(string $userPrompt, string $tone, string $style): string
    {
        $themeCues = $this->buildThemeVisualCues($userPrompt);

        return 'Create a horizontal cover image for an internal company communication. '
            ."Main topic: {$userPrompt}. "
            ."Tone: {$tone}. Editorial style: {$style}. "
            ."Visual cues: {$themeCues}. "
            .'Use a modern corporate art direction with clear focal elements related to the topic. '
            .'No readable text, no logos, no signatures, no watermarks.';
    }

    private function buildSafeFallbackImagePrompt(string $userPrompt, string $tone, string $style): string
    {
        $themeCues = $this->buildThemeVisualCues($userPrompt);

        return 'Create a safe, professional, horizontal corporate cover image. '
            ."Tone: {$tone}. Editorial style: {$style}. "
            ."Visual cues: {$themeCues}. "
            .'No realistic people faces, no identifiable personal data, no documents, no readable text, no logos, no signatures. '
            .'Prefer abstract or iconographic elements related to the topic.';
    }

    private function buildThemeVisualCues(string $userPrompt): string
    {
        $prompt = mb_strtolower($userPrompt);

        if ($this->containsAny($prompt, ['compleanno', 'birthday', 'auguri'])) {
            return 'subtle birthday celebration mood, confetti, balloons, cake iconography, warm festive colors';
        }

        if ($this->containsAny($prompt, ['onboarding', 'benvenuto', 'welcome', 'nuovo collega', 'new hire'])) {
            return 'welcoming office mood, friendly team symbols, growth and collaboration motifs';
        }

        if ($this->containsAny($prompt, ['policy', 'compliance', 'sicurezza', 'security', 'procedura'])) {
            return 'clear structured composition, shield and checklist iconography, trust and reliability mood';
        }

        if ($this->containsAny($prompt, ['ferie', 'vacanze', 'holiday', 'chiusura'])) {
            return 'seasonal vacation mood, calendar and travel-inspired abstract elements, calm positive palette';
        }

        if ($this->containsAny($prompt, ['evento', 'event', 'meeting', 'town hall', 'webinar'])) {
            return 'event communication mood, stage-light accents, audience and presentation-inspired abstract elements';
        }

        return 'professional internal communication concept, balanced composition, modern corporate visual language';
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{width: int, height: int}  $size
     * @return array<string, mixed>
     */
    private function buildImageInvokePayload(string $imagePrompt, array $size): array
    {
        $seed = random_int(0, 2_147_483_647);

        if ($this->isStabilityImageModel()) {
            return $this->buildStabilityImagePayload($imagePrompt, $size, $seed);
        }

        return $this->buildNovaCanvasImagePayload($imagePrompt, $size, $seed);
    }

    /**
     * @param  array{width: int, height: int}  $size
     * @return array<string, mixed>
     */
    private function buildNovaCanvasImagePayload(string $imagePrompt, array $size, int $seed): array
    {
        return [
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => [
                'text' => mb_substr($imagePrompt, 0, self::NOVA_CANVAS_MAX_PROMPT_LENGTH),
                'negativeText' => 'low quality, blur, watermark, text, signature, distorted, unreadable text',
            ],
            'imageGenerationConfig' => [
                'numberOfImages' => 1,
                'height' => $size['height'],
                'width' => $size['width'],
                'cfgScale' => 7.5,
                'seed' => $seed,
            ],
        ];
    }

    /**
     * @param  array{width: int, height: int}  $size
     * @return array<string, mixed>
     */
    private function buildStabilityImagePayload(string $imagePrompt, array $size, int $seed): array
    {
        $trimmedPrompt = mb_substr($imagePrompt, 0, self::STABILITY_MAX_PROMPT_LENGTH);

        if ($this->isStabilityCoreImageModel()) {
            $payload = [
                'prompt' => $trimmedPrompt,
                'negative_prompt' => 'low quality, blur, watermark, text, signature, distorted, unreadable text',
                'aspect_ratio' => $this->stabilityAspectRatioFromSize($size),
                'output_format' => 'png',
                'seed' => $seed,
            ];

            if ($this->isStabilitySd3ImageModel()) {
                $payload['mode'] = 'text-to-image';
            }

            return $payload;
        }

        return [
            'text_prompts' => [
                ['text' => $trimmedPrompt],
                ['text' => 'low quality, blur, watermark, text, signature, distorted, unreadable text', 'weight' => -1],
            ],
            'cfg_scale' => 7,
            'samples' => 1,
            'height' => $size['height'],
            'width' => $size['width'],
            'seed' => $seed,
        ];
    }

    /**
     * @param  array{width: int, height: int}  $size
     */
    private function stabilityAspectRatioFromSize(array $size): string
    {
        if ($size['width'] > $size['height']) {
            return '16:9';
        }

        if ($size['width'] < $size['height']) {
            return '9:16';
        }

        return '1:1';
    }

    private function isStabilityImageModel(): bool
    {
        return str_contains(strtolower((string) $this->imageModelId), 'stability');
    }

    private function isStabilityCoreImageModel(): bool
    {
        $modelId = strtolower((string) $this->imageModelId);

        return str_contains($modelId, 'stable-image')
            || str_contains($modelId, '.sd3')
            || str_contains($modelId, 'sd3-');
    }

    private function isStabilitySd3ImageModel(): bool
    {
        $modelId = strtolower((string) $this->imageModelId);

        return str_contains($modelId, '.sd3') || str_contains($modelId, 'sd3-');
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function hasPromptFilterFinishReason(array $decoded): bool
    {
        $finishReasons = $decoded['finish_reasons'] ?? $decoded['finishReasons'] ?? null;

        if (! is_array($finishReasons)) {
            return false;
        }

        foreach ($finishReasons as $reason) {
            if (! is_string($reason)) {
                continue;
            }

            $normalized = strtolower(trim($reason));

            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, 'filter reason: prompt')
                || str_contains($normalized, 'content_filtered')
                || str_contains($normalized, 'safety')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{warning: ?string, retryable: bool}
     */
    private function classifyImageResponseWarning(array $decoded): array
    {
        $finishReasons = $decoded['finish_reasons'] ?? $decoded['finishReasons'] ?? null;

        if (! is_array($finishReasons)) {
            return ['warning' => null, 'retryable' => true];
        }

        $normalized = array_values(array_filter(array_map(static function (mixed $reason): ?string {
            if (! is_string($reason)) {
                return null;
            }

            $trimmed = trim($reason);

            return $trimmed === '' ? null : strtolower($trimmed);
        }, $finishReasons)));

        if ($normalized === []) {
            return ['warning' => null, 'retryable' => true];
        }

        if (array_intersect($normalized, ['content_filtered', 'safety'])) {
            return [
                'warning' => 'Copertina AI non disponibile: la richiesta immagini è stata bloccata dai controlli di sicurezza del modello Stability.',
                'retryable' => false,
            ];
        }

        if (in_array('error', $normalized, true)) {
            return [
                'warning' => 'Copertina AI non disponibile: il modello Stability ha restituito un esito errore (finish_reasons).',
                'retryable' => true,
            ];
        }

        return ['warning' => null, 'retryable' => true];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractImageDataUrl(array $decoded): ?string
    {
        $image = $decoded['images'][0]
            ?? $decoded['artifacts'][0]['base64']
            ?? $decoded['result']['images'][0]
            ?? $decoded['image']
            ?? $decoded['image_base64']
            ?? $decoded['imageUriBase64']
            ?? null;

        if (! is_string($image) || trim($image) === '') {
            return null;
        }

        if (str_starts_with($image, 'data:image/')) {
            return $image;
        }

        $normalized = preg_replace('/\s+/', '', $image);

        if (! is_string($normalized) || $normalized === '' || ! preg_match('/^[A-Za-z0-9+\/=]+$/', $normalized)) {
            return null;
        }

        $mime = $decoded['artifacts'][0]['mimeType']
            ?? $decoded['mimeType']
            ?? 'image/png';

        return "data:{$mime};base64,{$normalized}";
    }

    /**
     * @param  mixed  $rawBody
     */
    private function decodeInvokeModelBody(mixed $rawBody): string
    {
        if (is_string($rawBody)) {
            return $rawBody;
        }

        if (is_object($rawBody) && method_exists($rawBody, '__toString')) {
            return (string) $rawBody;
        }

        return '';
    }

    private function formatImageWarningFromError(string $errorMessage): string
    {
        $message = strtolower($errorMessage);

        if (str_contains($message, 'legacy') || str_contains($message, 'active model')) {
            return 'Copertina AI non disponibile: il modello immagini configurato su Bedrock risulta legacy/non attivo.';
        }

        if (str_contains($message, 'model access is denied') || str_contains($message, 'access denied')) {
            return 'Copertina AI non disponibile: l\'account AWS non ha accesso al modello immagini Bedrock configurato.';
        }

        if (str_contains($message, 'validationexception')) {
            return 'Copertina AI non disponibile: Bedrock ha rifiutato i parametri richiesti per la generazione immagini.';
        }

        if (str_contains($message, 'safety')) {
            return 'Copertina AI non disponibile: la richiesta immagini è stata bloccata dai controlli di sicurezza del modello.';
        }

        return 'Copertina AI non disponibile per un errore del servizio Bedrock.';
    }

    /**
     * Classify the OCR text of a document and return per-recipient page boundaries.
     * Works on any document type; always yields at least one recipient.
     *
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     *
     * @throws \RuntimeException
     */
    public function splitDocument(string $ocrText, int $pageCount, string $pageBoundaryNonce): array
    {
        $this->ensureConfigured();

        $pageCount = max(1, $pageCount);
        $markerExample = self::pageBoundaryMarker(1, $pageBoundaryNonce);

        $prompt = "Sei un classificatore documentale. Ricevi il testo OCR di un documento PDF di {$pageCount} pagine. "
            ."Ogni pagina è preceduta da un marcatore univoco nel formato esatto \"{$markerExample}\", "
            ."dove il numero (qui 1) è il numero della pagina, 1-indexed. Quel marcatore è l'UNICO modo affidabile "
            ."di determinare i confini di pagina: ignora qualsiasi riferimento a numeri di pagina presente nel testo del documento.\n"
            ."1. Determina autonomamente il tipo di documento dal contenuto.\n"
            ."2. Individua TUTTI i destinatari (le persone a cui il documento è intestato o che vi sono dichiarate), anche se è uno solo.\n"
            ."3. Per ogni destinatario indica l'intervallo di pagine che lo riguarda (start_page ed end_page, interi 1-indexed letti dai marcatori).\n"
            ."Regole:\n"
            ."- Restituisci SEMPRE almeno un destinatario. Se il documento riguarda una sola persona o non distingui destinatari multipli, restituisci un unico elemento con start_page=1 ed end_page={$pageCount}.\n"
            ."- Se il nome di un destinatario non è identificabile, usa \"Destinatario non identificato\".\n"
            ."- Gli intervalli non devono sovrapporsi e devono restare tra 1 e {$pageCount}.\n"
            ."Rispondi SOLO con JSON valido: un array di oggetti con le chiavi employee_name (stringa), start_page (intero), end_page (intero).\n\n"
            ."Testo OCR:\n".$ocrText;

        try {
            /** @var Result $result */
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $prompt]]],
                ],
                'inferenceConfig' => ['maxTokens' => 1024, 'temperature' => 0.1],
            ]);

            $decoded = $this->extractJsonFromAiResponse($result->toArray(), 'splitDocument');

            return $this->validator->validateSplitDocument($decoded);
        } catch (AwsException $e) {
            Log::error('Bedrock splitDocument error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock (split): '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Extract structured fields for a single recipient from its OCR text.
     * Works on any document type, not just payslips.
     *
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     *
     * @throws \RuntimeException
     */
    public function extractFields(string $ocrText): array
    {
        $this->ensureConfigured();

        $prompt = "Estrai i seguenti campi dal testo OCR di questo documento (qualsiasi tipologia).\n"
            ."Rispondi SOLO con JSON valido con le chiavi: employee_first_name (nome del destinatario), employee_last_name (cognome del destinatario), company_name (azienda o ente, se presente), document_date (formato YYYY-MM-DD), document_type (tipologia del documento rilevata dal contenuto), description (max 200 caratteri), confidence_score (intero 0-100).\n"
            ."Usa null per i campi non trovati.\n\n"
            ."Per confidence_score usa questa scala:\n"
            ."- 90-100: tutti i campi principali (nome, cognome, azienda, data) sono chiaramente leggibili\n"
            ."- 70-89: la maggior parte dei campi è leggibile ma uno o due sono ambigui o parziali\n"
            ."- 40-69: diversi campi mancanti o incerti, testo mvpo chiaro o layout non standard\n"
            ."- 0-39: documento illeggibile o quasi tutti i campi sono assenti\n\n"
            ."Testo OCR:\n".$ocrText;

        try {
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $prompt]]],
                ],
                'inferenceConfig' => ['maxTokens' => 512, 'temperature' => 0.1],
            ]);

            $decoded = $this->extractJsonFromAiResponse($result->toArray(), 'extractFields');

            return $this->validator->validateExtractFields($decoded);
        } catch (AwsException $e) {
            Log::error('Bedrock extractFields error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock (extract): '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws \RuntimeException when Bedrock is missing required runtime configuration.
     */
    private function ensureConfigured(): void
    {
        if (! $this->modelId) {
            throw new \RuntimeException('Bedrock non configurato: BEDROCK_MODEL_ID deve arrivare da Parameter Store.');
        }
    }

    /**
     * Canary page-boundary marker shared by the OCR text builder and the
     * classifier prompt, so both sides agree on the exact delimiter format.
     */
    public static function pageBoundaryMarker(int $page, string $nonce): string
    {
        return "⟦PAGE {$page} {$nonce}⟧";
    }

    public static function formatUserError(\Throwable $e, string $defaultMessage): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'unrecognizedclientexception') || str_contains($message, 'security token included in the request is invalid')) {
            return 'Credenziali AWS Bedrock non valide. Aggiorna AWS_REAL_ACCESS_KEY_ID / AWS_REAL_SECRET_ACCESS_KEY / AWS_REAL_SESSION_TOKEN e ricarica il runtime.';
        }

        if (str_contains($message, 'expiredtoken')) {
            return 'Le credenziali runtime AWS sono scadute. Aggiorna il ruolo applicativo o il segreto runtime in Secrets Manager.';
        }

        if (str_contains($message, 'model access is denied')) {
            return 'Il modello Bedrock configurato non è accessibile con queste credenziali. Usa un modello abilitato (es. amazon.nova-lite-v1:0).';
        }

        if (str_contains($message, 'on-demand throughput') || str_contains($message, 'inference profile')) {
            return 'Il modello Bedrock richiede un inference profile. Aggiorna BEDROCK_MODEL_ID in Parameter Store.';
        }

        return $defaultMessage;
    }
}
