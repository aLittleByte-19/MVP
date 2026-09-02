<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;

final class FakeSendMessageRenderer implements SendMessageRendererPort
{
    public ?string $lastAttachment = null;

    public function renderPdf(SendMessageComposition $composition, ?string $attachmentPdf = null): string
    {
        $this->lastAttachment = $attachmentPdf;

        return 'pdf-bytes-'.$composition->subject.($attachmentPdf !== null ? '+'.$attachmentPdf : '');
    }
}
