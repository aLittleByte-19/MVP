<?php

namespace App\Mvp\Communications\Adapters\Outbound\Events;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Adapter secondario: implementa {@see CommunicationEventDispatcherPort}
 * sopra il dispatcher di eventi di Laravel. I listener sono registrati in
 * AppServiceProvider.
 */
class LaravelCommunicationEventDispatcher implements CommunicationEventDispatcherPort
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function dispatch(object $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
