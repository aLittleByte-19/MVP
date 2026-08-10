<?php

use App\Mvp\Communications\Domain\Exceptions\CoverPrecedesTextException;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationDraftBuilder;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi refactory.md
 * Compito 3 punto 2 e ADR 0010): CommunicationRecord e' un value object,
 * costruibile a mano senza alcun adapter.
 */
function fakeCommunicationRecord(array $overrides = []): CommunicationRecord
{
    return new CommunicationRecord(
        id: $overrides['id'] ?? 1,
        tenantId: $overrides['tenantId'] ?? 'tenant-1',
        prompt: $overrides['prompt'] ?? 'prompt',
        tone: $overrides['tone'] ?? 'tone',
        style: $overrides['style'] ?? 'style',
        generatedTitle: $overrides['generatedTitle'] ?? null,
        generatedBody: $overrides['generatedBody'] ?? null,
        imagePrompt: $overrides['imagePrompt'] ?? null,
        generationStatus: $overrides['generationStatus'] ?? 'pending',
        coverImagePath: $overrides['coverImagePath'] ?? null,
        coverImageMime: $overrides['coverImageMime'] ?? null,
        coverStatus: $overrides['coverStatus'] ?? 'pending',
        status: $overrides['status'] ?? 'draft',
        isFavorite: $overrides['isFavorite'] ?? false,
        rating: $overrides['rating'] ?? null,
        workflowExecutionArn: $overrides['workflowExecutionArn'] ?? null,
    );
}

test('withGeneratedText produces the persistence content', function () {
    $builder = CommunicationDraftBuilder::fromRecord(fakeCommunicationRecord());
    $generated = new GeneratedCommunicationText(title: 'Titolo', body: 'Corpo', imagePrompt: 'Direzione visiva');

    expect($builder->withGeneratedText($generated))->toBe([
        'generated_title' => 'Titolo',
        'generated_body' => 'Corpo',
        'image_prompt' => 'Direzione visiva',
    ]);
});

test('hasGeneratedText reflects whether the record already has a body', function () {
    expect(CommunicationDraftBuilder::fromRecord(fakeCommunicationRecord())->hasGeneratedText())->toBeFalse()
        ->and(CommunicationDraftBuilder::fromRecord(fakeCommunicationRecord(['generatedBody' => 'x']))->hasGeneratedText())->toBeTrue();
});

test('withGeneratedCover refuses to run before the text step', function () {
    $builder = CommunicationDraftBuilder::fromRecord(fakeCommunicationRecord(['generatedBody' => null]));
    $image = new GeneratedCommunicationImage(bytes: 'bytes', mime: 'image/png', warning: null, reason: null);

    expect(fn () => $builder->withGeneratedCover($image, 'communications/covers/1/x.png'))
        ->toThrow(CoverPrecedesTextException::class);
});

test('withGeneratedCover produces the persistence content once the text exists', function () {
    $builder = CommunicationDraftBuilder::fromRecord(fakeCommunicationRecord(['generatedBody' => 'Corpo gia generato']));
    $image = new GeneratedCommunicationImage(bytes: 'immagine-bytes', mime: 'image/png', warning: null, reason: null);

    expect($builder->withGeneratedCover($image, 'communications/covers/1/x.png'))->toBe([
        'cover_image_path' => 'communications/covers/1/x.png',
        'cover_image_mime' => 'image/png',
        'cover_image_size' => strlen('immagine-bytes'),
    ]);
});
