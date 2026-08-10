<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\Commands\UploadDocumentCommand;

/**
 * Porta primaria: caricamento di un documento e avvio dell'elaborazione.
 * Invocata oggi dall'adapter HTTP (DocumentController); una porta primaria
 * puo' avere piu' adapter primari, non solo uno.
 */
interface UploadDocumentUseCase
{
    /**
     * @return int Id dell'OriginalDocument creato.
     */
    public function upload(UploadDocumentCommand $command): int;
}
