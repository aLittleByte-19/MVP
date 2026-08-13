<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationRated;
use App\Mvp\Communications\Domain\Ports\Inbound\RateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identity\Actor;
use Psr\Clock\ClockInterface;

class RateCommunicationService implements RateCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly ClockInterface $clock,
    ) {}

    public function rate(int $communicationId, int $rating, ?string $comment, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $normalizedComment = $comment !== null ? trim($comment) : null;
        $normalizedComment = $normalizedComment === '' ? null : $normalizedComment;

        $communication->rate($rating, $normalizedComment, $actor->id, $this->clock->now());
        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationRated($communicationId, $actor, $rating, $normalizedComment !== null));
    }
}
