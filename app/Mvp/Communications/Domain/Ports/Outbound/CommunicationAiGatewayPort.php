<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

/**
 * Porta secondaria verso il classificatore AI: generazione testo e copertina
 * di una comunicazione. Nessun riferimento a Bedrock o all'SDK AWS.
 */
interface CommunicationAiGatewayPort
{
    public function generateText(string $prompt, string $tone, string $style): GeneratedCommunicationText;

    public function generateImage(string $prompt, string $tone, string $style, ?string $modelImagePrompt): GeneratedCommunicationImage;
}
