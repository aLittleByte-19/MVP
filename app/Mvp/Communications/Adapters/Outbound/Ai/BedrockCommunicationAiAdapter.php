<?php

namespace App\Mvp\Communications\Adapters\Outbound\Ai;

use App\Mvp\Ai\BedrockService;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

/**
 * Adapter secondario: implementa {@see CommunicationAiGatewayPort} sopra
 * {@see BedrockService}, lo stesso client Bedrock condiviso col dominio
 * Documents (che lo usa per split/estrazione tramite BedrockDocumentAiAdapter).
 */
class BedrockCommunicationAiAdapter implements CommunicationAiGatewayPort
{
    public function __construct(private readonly BedrockService $bedrock) {}

    public function generateText(string $prompt, string $tone, string $style): GeneratedCommunicationText
    {
        $generated = $this->bedrock->generateCommunication($prompt, $tone, $style);

        return new GeneratedCommunicationText(
            title: $generated['title'],
            body: $generated['body'],
            imagePrompt: $generated['image_prompt'] ?? null,
        );
    }

    public function generateImage(string $prompt, string $tone, string $style, ?string $modelImagePrompt): GeneratedCommunicationImage
    {
        $image = $this->bedrock->generateCommunicationImageWithMeta($prompt, $tone, $style, $modelImagePrompt);

        return new GeneratedCommunicationImage(
            bytes: $image['bytes'],
            mime: $image['mime'],
            warning: $image['warning'],
            reason: $image['reason'],
        );
    }
}
