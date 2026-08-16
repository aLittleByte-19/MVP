<?php

namespace App\Mvp\Documents\Adapters\Outbound\Events;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Adapter secondario: implementa {@see DocumentEventDispatcherPort} sopra il
 * dispatcher di eventi di Laravel. I listener sono registrati in
 * AppServiceProvider.
 */
class LaravelDocumentEventDispatcher implements DocumentEventDispatcherPort
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function dispatch(object $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
