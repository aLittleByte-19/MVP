<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Dati necessari a comporre il messaggio di invio precompilato di un
 * sotto-documento (UC-48/48.1/48.2/48.3). Nessun riferimento a Eloquent.
 */
final class SendMessageContext
{
    public function __construct(
        public readonly ?string $employeeFirstName,
        public readonly ?string $employeeLastName,
        public readonly ?string $documentType,
        public readonly ?string $companyName,
        public readonly ?string $documentDateDisplay,
        public readonly ?string $description,
        public readonly ?string $sendRecipientOverride,
        public readonly ?string $sendSubjectOverride,
        public readonly ?string $sendBodyOverride,
        public readonly string $originalFilename,
        public readonly string $sendStatus,
    ) {}
}
