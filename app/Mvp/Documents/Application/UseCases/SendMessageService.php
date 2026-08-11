<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Events\SendMessageExported;
use App\Mvp\Documents\Domain\Events\SendMessageOverridesCorrected;
use App\Mvp\Documents\Domain\Ports\Inbound\SendMessageUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Documents\Domain\ValueObjects\RenderedSendMessage;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Enums\SendStatus;
use App\Mvp\Identity\MvpUser;
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

    public function preview(int $subDocumentId, ?MvpUser $actor): RenderedSendMessage
    {
        $composition = $this->compose($this->documents->findSendMessageContext($subDocumentId));

        return new RenderedSendMessage($this->renderer->renderPdf($composition), $this->filename($composition, $subDocumentId));
    }

    public function export(int $subDocumentId, ?MvpUser $actor): RenderedSendMessage
    {
        $context = $this->documents->findSendMessageContext($subDocumentId);
        $composition = $this->compose($context);

        // Il recapito avviene fuori dalla piattaforma: il download del PDF e'
        // l'ultimo evento osservabile, quindi e' quello che marca l'invio.
        // Transizione a senso unico: un secondo download non cambia lo stato.
        if ($context->sendStatus === SendStatus::Pending->value) {
            $this->documents->updateSubDocument($subDocumentId, ['send_status' => SendStatus::Sent]);
            $this->events->dispatch(new SendMessageExported($subDocumentId, $actor));
        }

        return new RenderedSendMessage($this->renderer->renderPdf($composition), $this->filename($composition, $subDocumentId));
    }

    public function updateOverrides(int $subDocumentId, array $overrides, ?MvpUser $actor): void
    {
        $updates = [];

        if (array_key_exists('recipient', $overrides)) {
            $updates['send_recipient_override'] = $overrides['recipient'];
        }

        if (array_key_exists('subject', $overrides)) {
            $updates['send_subject_override'] = $overrides['subject'];
        }

        if (array_key_exists('body', $overrides)) {
            $updates['send_body_override'] = $overrides['body'];
        }

        if ($updates !== []) {
            $this->documents->updateSubDocument($subDocumentId, $updates);
        }

        $this->events->dispatch(new SendMessageOverridesCorrected($subDocumentId, $actor, array_keys($overrides)));
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
