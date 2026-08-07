<?php

namespace App\Mvp\Communications\Services;

use App\Models\PromptConfiguration;

/**
 * Etichettatura delle configurazioni di prompt salvate (UC-19): un nome
 * vuoto o gia' in uso per il tenant viene sostituito da un'etichetta
 * progressiva ("Senza nome (1)", "(2)", ...), mai da un errore.
 */
class PromptConfigurationNamer
{
    public function resolve(string $tenantId, ?string $requestedName): string
    {
        $trimmed = trim((string) $requestedName);

        if ($trimmed !== '' && ! $this->nameExists($tenantId, $trimmed)) {
            return $trimmed;
        }

        $counter = 1;

        while ($this->nameExists($tenantId, "Senza nome ({$counter})")) {
            $counter++;
        }

        return "Senza nome ({$counter})";
    }

    private function nameExists(string $tenantId, string $name): bool
    {
        return PromptConfiguration::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->exists();
    }
}
