<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\PromptConfigurationDeleted;

class RecordPromptConfigurationDeleted
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PromptConfigurationDeleted $event): void
    {
        $this->audit->record('mvp-prompt-configuration-deleted', $event->actor, 'prompt_configuration', (string) $event->configurationId);
    }
}
