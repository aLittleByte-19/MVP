<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

use App\Mvp\Communications\Domain\Exceptions\CoverPrecedesTextException;

/**
 * Assembla in modo esplicito il contenuto di una Communication attraverso i
 * passi asincroni della pipeline (generate_text -> generate_cover): rende
 * visibile l'invariante "dopo generate_text titolo e corpo sono impostati,
 * la copertina no" invece di lasciarla implicita nei singoli Application
 * Service (vedi ADR 0010). Non tocca lo stato di workflow (cover_status,
 * generation_status, audit): quello resta responsabilita' del chiamante,
 * che lo unisce all'array restituito qui prima di persisterlo.
 */
final class CommunicationDraftBuilder
{
    private function __construct(
        private readonly bool $hasGeneratedText,
    ) {}

    public static function fromRecord(CommunicationRecord $record): self
    {
        return new self(hasGeneratedText: $record->generatedBody !== null);
    }

    public function hasGeneratedText(): bool
    {
        return $this->hasGeneratedText;
    }

    /**
     * @return array{generated_title: string, generated_body: string, image_prompt: ?string}
     */
    public function withGeneratedText(GeneratedCommunicationText $generated): array
    {
        return [
            'generated_title' => $generated->title,
            'generated_body' => $generated->body,
            'image_prompt' => $generated->imagePrompt,
        ];
    }

    /**
     * @return array{cover_image_path: string, cover_image_mime: string, cover_image_size: int}
     *
     * @throws CoverPrecedesTextException se il testo non e' ancora stato generato.
     */
    public function withGeneratedCover(GeneratedCommunicationImage $image, string $path): array
    {
        if (! $this->hasGeneratedText) {
            throw new CoverPrecedesTextException;
        }

        return [
            'cover_image_path' => $path,
            'cover_image_mime' => $image->mime,
            'cover_image_size' => strlen($image->bytes ?? ''),
        ];
    }
}
