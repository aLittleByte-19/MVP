<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\PromptConfigurationSaved;

class RecordPromptConfigurationSaved
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PromptConfigurationSaved $event): void
    {
        $this->audit->record(
            'mvp-prompt-configuration-saved',
            $event->actor,
            'prompt_configuration',
            (string) $event->configurationId,
            ['name' => $event->name],
        );
    }
}
