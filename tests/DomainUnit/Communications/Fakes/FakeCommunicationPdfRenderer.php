<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationPdfRendererPort;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPdfContext;

final class FakeCommunicationPdfRenderer implements CommunicationPdfRendererPort
{
    public function fingerprint(CommunicationPdfContext $context): string
    {
        return 'fingerprint-'.$context->id;
    }

    public function render(CommunicationPdfContext $context): string
    {
        return 'pdf-bytes-'.$context->id;
    }

    public function filename(CommunicationPdfContext $context): string
    {
        return 'comunicazione-'.$context->id.'.pdf';
    }
}
