<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Commands\SavePromptConfigurationCommand;
use App\Mvp\Communications\Domain\Exceptions\PromptConfigurationNotAuthorizedException;
use App\Mvp\Support\Identity\Actor;

/**
 * Porta primaria: preset di prompt riutilizzabili (UC-19). Il controllo di
 * ownership (tenant) vive nel caso d'uso: delete() rifiuta un preset di un
 * altro tenant invece di fidarsi solo dell'adapter HTTP.
 */
interface PromptConfigurationUseCase
{
    public function save(SavePromptConfigurationCommand $command): int;

    /**
     * @throws PromptConfigurationNotAuthorizedException
     */
    public function delete(int $configurationId, Actor $actor): void;
}
