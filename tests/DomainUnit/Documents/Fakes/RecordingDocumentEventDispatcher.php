<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;

final class RecordingDocumentEventDispatcher implements DocumentEventDispatcherPort
{
    /** @var list<object> */
    private array $events = [];

    public function dispatch(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<object>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function hasDispatched(string $eventClass): bool
    {
        foreach ($this->events as $event) {
            if ($event instanceof $eventClass) {
                return true;
            }
        }

        return false;
    }
}
