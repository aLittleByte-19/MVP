<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Support\Identity\Actor;

/**
 * Porta primaria: elimina un sotto-documento; se era l'ultimo del documento
 * originale, elimina anche quest'ultimo (con i suoi task workflow).
 */
interface DeleteDocumentUseCase
{
    public function delete(int $subDocumentId, ?Actor $actor): void;
}
