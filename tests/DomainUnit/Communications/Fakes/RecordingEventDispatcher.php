<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;

final class RecordingEventDispatcher implements CommunicationEventDispatcherPort
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
