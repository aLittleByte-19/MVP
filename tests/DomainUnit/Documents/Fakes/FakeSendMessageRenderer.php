<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;

final class FakeSendMessageRenderer implements SendMessageRendererPort
{
    public function renderPdf(SendMessageComposition $composition): string
    {
        return 'pdf-bytes-'.$composition->subject;
    }
}
