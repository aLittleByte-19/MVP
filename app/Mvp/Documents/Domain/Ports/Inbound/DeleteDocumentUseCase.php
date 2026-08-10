<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Identity\MvpUser;

/**
 * Porta primaria: elimina un sotto-documento; se era l'ultimo del documento
 * originale, elimina anche quest'ultimo (con i suoi task workflow).
 */
interface DeleteDocumentUseCase
{
    public function delete(int $subDocumentId, ?MvpUser $actor): void;
}
