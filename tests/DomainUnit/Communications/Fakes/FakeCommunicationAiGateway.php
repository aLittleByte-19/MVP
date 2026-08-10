<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

final class FakeCommunicationAiGateway implements CommunicationAiGatewayPort
{
    private ?GeneratedCommunicationText $text = null;

    private ?\Throwable $textException = null;

    private ?GeneratedCommunicationImage $image = null;

    public function willReturnText(GeneratedCommunicationText $text): void
    {
        $this->text = $text;
        $this->textException = null;
    }

    public function willThrowOnText(\Throwable $exception): void
    {
        $this->textException = $exception;
    }

    public function willReturnImage(GeneratedCommunicationImage $image): void
    {
        $this->image = $image;
    }

    public function generateText(string $prompt, string $tone, string $style): GeneratedCommunicationText
    {
        if ($this->textException !== null) {
            throw $this->textException;
        }

        return $this->text ?? throw new \LogicException('FakeCommunicationAiGateway::willReturnText() non configurato.');
    }

    public function generateImage(string $prompt, string $tone, string $style, ?string $modelImagePrompt): GeneratedCommunicationImage
    {
        return $this->image ?? throw new \LogicException('FakeCommunicationAiGateway::willReturnImage() non configurato.');
    }
}
