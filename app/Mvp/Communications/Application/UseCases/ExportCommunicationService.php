<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Entities\Communication;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotReadyForExportException;
use App\Mvp\Communications\Domain\Ports\Inbound\ExportCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationPdfRendererPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPdfContext;
use App\Mvp\Communications\Domain\ValueObjects\RenderedCommunicationPdf;

class ExportCommunicationService implements ExportCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationPdfRendererPort $renderer,
    ) {}

    public function fingerprint(int $communicationId): string
    {
        return $this->renderer->fingerprint($this->context($communicationId));
    }

    public function render(int $communicationId): RenderedCommunicationPdf
    {
        $context = $this->context($communicationId);

        return new RenderedCommunicationPdf($this->renderer->render($context), $this->renderer->filename($context));
    }

    private function context(int $communicationId): CommunicationPdfContext
    {
        $communication = $this->communications->findCommunication($communicationId);
        $this->assertReadyForExport($communication);

        return new CommunicationPdfContext(
            id: $communication->id,
            title: $communication->generatedTitle(),
            body: $communication->generatedBody(),
            coverStatus: $communication->coverStatus()->value,
            coverImagePath: $communication->coverImagePath(),
            coverImageMime: $communication->coverImageMime(),
        );
    }

    private function assertReadyForExport(Communication $communication): void
    {
        if (! $communication->isReadyForExport()) {
            throw new CommunicationNotReadyForExportException;
        }
    }
}
