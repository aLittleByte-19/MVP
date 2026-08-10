<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CoverImageSource;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Observability\MetricsRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function generate(int $communicationId): array
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->coverStatus === CoverImageStatus::Ready->value) {
            return ['skipped' => true, 'coverStatus' => CoverImageStatus::Ready->value];
        }

        $this->communications->updateCommunication($communicationId, [
            'cover_status' => CoverImageStatus::Processing,
            'cover_error' => null,
        ]);

        try {
            $image = $this->ai->generateImage($communication->prompt, $communication->tone, $communication->style, $communication->imagePrompt);
        } catch (\Throwable $e) {
            Log::warning('Communication cover generation failed', ['communication_id' => $communicationId, 'message' => $e->getMessage()]);
            $this->degrade($communicationId, $communication->tenantId, 'Copertina AI non disponibile per un errore del servizio immagini.', 'model_error');

            return ['skipped' => false, 'coverStatus' => CoverImageStatus::Failed->value];
        }

        if ($image->bytes === null) {
            $this->degrade($communicationId, $communication->tenantId, $image->warning ?? 'Copertina AI non disponibile al momento.', $image->reason ?? 'model_error');

            return ['skipped' => false, 'coverStatus' => CoverImageStatus::Failed->value];
        }

        try {
            $path = $this->newPath($communicationId, $image->mime);
            $this->storage->store($path, $image->bytes);
        } catch (\Throwable $e) {
            Log::error('Communication cover storage failed', ['communication_id' => $communicationId, 'message' => $e->getMessage()]);
            $this->degrade($communicationId, $communication->tenantId, 'Copertina non disponibile: storage non raggiungibile.', 'storage_error');

            return ['skipped' => false, 'coverStatus' => CoverImageStatus::Failed->value];
        }

        if ($communication->coverImagePath !== null) {
            $this->storage->delete($communication->coverImagePath);
        }

        $this->communications->updateCommunication($communicationId, [
            'cover_image_path' => $path,
            'cover_image_mime' => $image->mime,
            'cover_image_size' => strlen($image->bytes),
            'cover_image_source' => CoverImageSource::Ai,
            'cover_status' => CoverImageStatus::Ready,
            'cover_error' => null,
        ]);
        $this->metrics->recordDomainCounter('communication_covers_generated_total', ['source' => CoverImageSource::Ai->value]);
        $this->audit->record(
            'mvp-communication-cover-generated',
            resourceType: 'communication',
            resourceId: (string) $communicationId,
            metadata: ['mime' => $image->mime],
            tenantId: $communication->tenantId,
        );

        return ['skipped' => false, 'coverStatus' => CoverImageStatus::Ready->value];
    }

    private function degrade(int $communicationId, string $tenantId, string $warning, string $reason): void
    {
        $this->communications->updateCommunication($communicationId, [
            'cover_status' => CoverImageStatus::Failed,
            'cover_error' => $warning,
        ]);
        $this->metrics->recordDomainCounter('communication_covers_failed_total', ['reason' => $reason]);
        $this->audit->record(
            'mvp-communication-cover-degraded',
            resourceType: 'communication',
            resourceId: (string) $communicationId,
            metadata: ['reason' => $reason],
            tenantId: $tenantId,
        );
    }

    private function newPath(int $communicationId, string $mime): string
    {
        return sprintf(
            '%s/%d/%s.%s',
            trim((string) config('mvp.communications.cover_prefix', 'communications/covers'), '/'),
            $communicationId,
            Str::uuid()->toString(),
            self::EXTENSIONS[strtolower($mime)] ?? 'png',
        );
    }
}
