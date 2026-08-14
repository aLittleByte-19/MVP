<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Enums\SendStatus;
use App\Mvp\Documents\Domain\Events\SendMessageExported;
use App\Mvp\Documents\Domain\Events\SendMessageOverridesCorrected;
use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Ports\Inbound\SendMessageUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Documents\Domain\ValueObjects\RenderedSendMessage;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
use App\Mvp\Support\Identity\Actor;
use Illuminate\Support\Str;

/**
 * Applicazione: compone il messaggio di invio precompilato dai dati gia'
 * estratti (nessuna generazione AI, UC-48) e ne orchestra anteprima,
 * esportazione e correzione manuale attraverso le porte del dominio.
 */
class SendMessageService implements SendMessageUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SendMessageRendererPort $renderer,
        private readonly DocumentEventDispatcherPort $events,
    ) {}

    public function preview(int $subDocumentId, Actor $actor): RenderedSendMessage
    {
        $this->assertActorOwnsSubDocument($subDocumentId, $actor);
        $composition = $this->compose($this->documents->findSendMessageContext($subDocumentId));

        return new RenderedSendMessage($this->renderer->renderPdf($composition), $this->filename($composition, $subDocumentId));
    }

    public function export(int $subDocumentId, Actor $actor): RenderedSendMessage
    {
        $this->assertActorOwnsSubDocument($subDocumentId, $actor);
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $composition = $this->compose($this->documents->findSendMessageContext($subDocumentId));

        // Il recapito avviene fuori dalla piattaforma: il download del PDF e'
        // l'ultimo evento osservabile, quindi e' quello che marca l'invio.
        // Transizione a senso unico: un secondo download non cambia lo stato.
        if ($subDocument->sendStatus() === SendStatus::Pending) {
            $subDocument->markSent();
            $this->documents->saveSubDocument($subDocument);
            $this->events->dispatch(new SendMessageExported($subDocumentId, $actor));
        }

        return new RenderedSendMessage($this->renderer->renderPdf($composition), $this->filename($composition, $subDocumentId));
    }

    public function updateOverrides(int $subDocumentId, array $overrides, Actor $actor): void
    {
        $this->assertActorOwnsSubDocument($subDocumentId, $actor);
        $changes = SubDocumentChanges::none();

        if (array_key_exists('recipient', $overrides)) {
            $changes = $changes->withSendRecipientOverride($overrides['recipient']);
        }

        if (array_key_exists('subject', $overrides)) {
            $changes = $changes->withSendSubjectOverride($overrides['subject']);
        }

        if (array_key_exists('body', $overrides)) {
            $changes = $changes->withSendBodyOverride($overrides['body']);
        }

        if (! $changes->isEmpty()) {
            $this->documents->updateSubDocument($subDocumentId, $changes);
        }

        $this->events->dispatch(new SendMessageOverridesCorrected($subDocumentId, $actor, array_keys($overrides)));
    }

    private function assertActorOwnsSubDocument(int $subDocumentId, Actor $actor): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $original = $this->documents->findOriginalDocument($subDocument->originalDocumentId);

        if ($original->tenantId !== $actor->tenantId) {
            throw new DocumentNotAuthorizedException;
        }
    }

    private function compose(SendMessageContext $context): SendMessageComposition
    {
        $employeeName = trim(($context->employeeFirstName ?? '').' '.($context->employeeLastName ?? ''));

        return new SendMessageComposition(
            recipient: $context->sendRecipientOverride
                ?: ($employeeName !== '' ? $employeeName : 'Destinatario non disponibile'),
            subject: $context->sendSubjectOverride
                ?: ($context->documentType ? "Invio documento — {$context->documentType}" : 'Invio documento'),
            body: $context->sendBodyOverride
                ?: $this->composeBody($employeeName, $context->documentType, $context->companyName, $context->documentDateDisplay, $context->description),
            attachmentFilename: $context->originalFilename,
        );
    }

    private function composeBody(string $employeeName, ?string $documentType, ?string $companyName, ?string $documentDate, ?string $description): string
    {
        $greeting = $employeeName !== '' ? "Gentile {$employeeName}," : 'Gentile destinatario,';
        $documentLabel = $documentType ?: 'documento';
        $reference = "in allegato trova il documento \"{$documentLabel}\"";

        if ($companyName) {
            $reference .= " relativo a {$companyName}";
        }

        if ($documentDate) {
            $reference .= " del {$documentDate}";
        }

        $reference .= '.';

        $lines = [$greeting, '', $reference];

        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = 'Cordiali saluti.';

        return implode("\n", $lines);
    }

    private function filename(SendMessageComposition $composition, int $subDocumentId): string
    {
        $slug = Str::slug($composition->subject);

        return ($slug !== '' ? $slug : 'messaggio-invio-'.$subDocumentId).'.pdf';
    }
}
