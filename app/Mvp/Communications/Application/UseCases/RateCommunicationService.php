<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Communications\Domain\Ports\Inbound\RateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Identity\MvpUser;

class RateCommunicationService implements RateCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly AuditLogger $audit,
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

        $this->audit->record(
            'mvp-communication-rated',
            $actor,
            'communication',
            (string) $communicationId,
            ['rating' => $rating, 'has_comment' => $normalizedComment !== null],
        );
    }
}
