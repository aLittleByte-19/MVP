<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Commands\SavePromptConfigurationCommand;
use App\Mvp\Identity\MvpUser;

/**
 * Porta primaria: preset di prompt riutilizzabili (UC-19). Il controllo di
 * ownership (tenant) resta nell'adapter HTTP, come per DeleteDocumentUseCase:
 * e' autorizzazione di trasporto, non una regola di dominio (vedi ADR 0010).
 * L'attore passato a delete() serve solo a tracciare l'audit.
 */
interface PromptConfigurationUseCase
{
    public function save(SavePromptConfigurationCommand $command): int;

    public function delete(int $configurationId, MvpUser $actor): void;
}
