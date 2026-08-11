<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Communications\Domain\Events\AiOutputRejected;
use App\Mvp\Communications\Domain\Events\CommunicationTextGenerated;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationTextUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationDraftBuilder;

/**
 * Il testo e' la comunicazione: se fallisce, l'esecuzione fallisce
 * (nessun degrado, a differenza della copertina).
 */
class GenerateCommunicationTextService implements GenerateCommunicationTextUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationAiGatewayPort $ai,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function generate(int $communicationId): array
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->generatedBody) {
            return ['skipped' => true, 'title' => null];
        }

        try {
            $generated = $this->ai->generateText($communication->prompt, $communication->tone, $communication->style);
        } catch (InvalidAiOutputException $e) {
            $this->events->dispatch(new AiOutputRejected($communicationId, $communication->tenantId, $e->operation(), $e->errors()));
            $this->communications->updateCommunication($communicationId, CommunicationChanges::none()
                ->withErrorMessage('La risposta del servizio AI non è valida. Riprova la generazione.'));

            throw $e;
        }

        $builder = CommunicationDraftBuilder::fromRecord($communication);
        $this->communications->updateCommunication($communicationId, CommunicationChanges::fromRawFields($builder->withGeneratedText($generated)));
        $this->events->dispatch(new CommunicationTextGenerated($communicationId, $communication->tenantId, $communication->tone, $communication->style));

        return ['skipped' => false, 'title' => $generated->title];
    }
}
