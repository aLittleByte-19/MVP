<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationRated;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Communications\Domain\Ports\Inbound\RateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Identity\MvpUser;

class RateCommunicationService implements RateCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function rate(int $communicationId, int $rating, ?string $comment, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->rating !== null) {
            throw new CommunicationAlreadyRatedException;
        }

        $normalizedComment = $comment !== null ? trim($comment) : null;
        $normalizedComment = $normalizedComment === '' ? null : $normalizedComment;

        $this->communications->updateCommunication($communicationId, [
            'rating' => $rating,
            'rating_comment' => $normalizedComment,
            'rated_at' => now(),
            'rated_by' => $actor->id,
        ]);

        $this->events->dispatch(new CommunicationRated($communicationId, $actor, $rating, $normalizedComment !== null));
    }
}
