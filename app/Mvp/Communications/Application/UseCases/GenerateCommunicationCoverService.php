<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Entities\Communication;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationCoverGenerated;
use App\Mvp\Communications\Domain\Exceptions\CoverPrecedesTextException;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use Psr\Log\LoggerInterface;

/**
 * La copertina e' un arricchimento: un fallimento viene registrato sulla
 * comunicazione e il task si chiude comunque con successo, altrimenti una
 * comunicazione valida verrebbe persa per un'immagine mancante (ADR 0005).
 */
class GenerateCommunicationCoverService implements GenerateCommunicationCoverUseCase
{
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $storage,
        private readonly CommunicationAiGatewayPort $ai,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly LoggerInterface $logger,
        private readonly UniqueIdGeneratorPort $ids,
        private readonly string $coverPathPrefix = 'communications/covers',
    ) {}

    public function generate(int $communicationId): array
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->coverStatus() === CoverImageStatus::Ready) {
            return ['skipped' => true, 'coverStatus' => CoverImageStatus::Ready->value];
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()
            ->withCoverStatus(CoverImageStatus::Processing)
            ->withCoverError(null));

        try {
            $image = $this->ai->generateImage($communication->prompt, $communication->tone, $communication->style, $communication->imagePrompt());
        } catch (\Throwable $e) {
            $this->logger->warning('Communication cover generation failed', ['communication_id' => $communicationId, 'message' => $e->getMessage()]);
            $this->degrade($communication, 'Copertina AI non disponibile per un errore del servizio immagini.', 'model_error');

            return ['skipped' => false, 'coverStatus' => CoverImageStatus::Failed->value];
        }

        if ($image->bytes === null) {
            $this->degrade($communication, $image->warning ?? 'Copertina AI non disponibile al momento.', $image->reason ?? 'model_error');

            return ['skipped' => false, 'coverStatus' => CoverImageStatus::Failed->value];
        }

        // L'invariante (il testo precede la copertina) e' verificata qui,
        // fuori dal try/catch di storage: se scattasse indicherebbe un bug
        // di ordinamento del workflow, non un errore di storage da
        // degradare silenziosamente.
        if (! $communication->hasGeneratedText()) {
            throw new CoverPrecedesTextException;
        }

        $oldCoverPath = $communication->coverImagePath();
        $path = $this->newPath($communicationId, $image->mime);

        try {
            $this->storage->store($path, $image->bytes);
        } catch (\Throwable $e) {
            $this->logger->error('Communication cover storage failed', ['communication_id' => $communicationId, 'message' => $e->getMessage()]);
            $this->degrade($communication, 'Copertina non disponibile: storage non raggiungibile.', 'storage_error');

            return ['skipped' => false, 'coverStatus' => CoverImageStatus::Failed->value];
        }

        $communication->applyGeneratedCover($image, $path);
        $this->communications->saveCommunication($communication);

        if ($oldCoverPath !== null) {
            $this->storage->delete($oldCoverPath);
        }

        $this->events->dispatch(new CommunicationCoverGenerated($communicationId, $communication->tenantId, $image->mime));

        return ['skipped' => false, 'coverStatus' => CoverImageStatus::Ready->value];
    }

    private function degrade(Communication $communication, string $warning, string $reason): void
    {
        $communication->degradeCover($warning);
        $this->communications->saveCommunication($communication);
        $this->events->dispatch(new CommunicationCoverDegraded($communication->id, $communication->tenantId, $reason, $warning));
    }

    private function newPath(int $communicationId, string $mime): string
    {
        return sprintf(
            '%s/%d/%s.%s',
            trim($this->coverPathPrefix, '/'),
            $communicationId,
            $this->ids->generate(),
            self::EXTENSIONS[strtolower($mime)] ?? 'png',
        );
    }
}
