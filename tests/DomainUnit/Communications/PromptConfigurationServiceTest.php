<?php

use App\Mvp\Communications\Application\UseCases\PromptConfigurationService;
use App\Mvp\Communications\Domain\Commands\SavePromptConfigurationCommand;
use App\Mvp\Communications\Domain\Events\PromptConfigurationDeleted;
use App\Mvp\Communications\Domain\Events\PromptConfigurationSaved;
use App\Mvp\Identity\MvpUser;
use Tests\DomainUnit\Communications\Fakes\InMemoryPromptConfigurationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). Funzione locale
 * (non condivisa fra file): con `--parallel` ogni worker Paratest carica solo
 * un sottoinsieme dei file di test, quindi una funzione globale dichiarata
 * altrove puo' non essere disponibile nello stesso processo.
 */
function fakePromptConfigurationActor(): MvpUser
{
    return new MvpUser('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}
test('save keeps a distinct requested name and dispatches PromptConfigurationSaved', function () {
    $configurations = new InMemoryPromptConfigurationRepository;
    $events = new RecordingEventDispatcher;

    $id = (new PromptConfigurationService($configurations, $events))->save(new SavePromptConfigurationCommand(
        name: 'Comunicazione ferie',
        prompt: 'Avvisa il personale delle nuove ferie disponibili.',
        tone: 'Chiaro e diretto',
        style: 'Testo informativo',
        actor: fakePromptConfigurationActor(),
    ));

    expect($configurations->has($id))->toBeTrue()
        ->and($events->hasDispatched(PromptConfigurationSaved::class))->toBeTrue();
});

test('save assigns a progressive label when the name is blank', function () {
    $configurations = new InMemoryPromptConfigurationRepository;
    $events = new RecordingEventDispatcher;
    $service = new PromptConfigurationService($configurations, $events);

    $service->save(new SavePromptConfigurationCommand(null, 'prompt', 'tono', 'stile', fakePromptConfigurationActor()));
    $service->save(new SavePromptConfigurationCommand('', 'prompt', 'tono', 'stile', fakePromptConfigurationActor()));

    $dispatched = array_filter($events->events(), fn ($event) => $event instanceof PromptConfigurationSaved);
    $names = array_map(fn ($event) => $event->name, $dispatched);

    expect($names)->toBe(['Senza nome (1)', 'Senza nome (2)']);
});

test('save falls back to a progressive label when the requested name is already used for the tenant', function () {
    $configurations = new InMemoryPromptConfigurationRepository;
    $events = new RecordingEventDispatcher;
    $service = new PromptConfigurationService($configurations, $events);
    $actor = fakePromptConfigurationActor();

    $service->save(new SavePromptConfigurationCommand('Ferie', 'prompt', 'tono', 'stile', $actor));
    $service->save(new SavePromptConfigurationCommand('Ferie', 'prompt', 'tono', 'stile', $actor));

    $dispatched = array_values(array_filter($events->events(), fn ($event) => $event instanceof PromptConfigurationSaved));

    expect($dispatched[0]->name)->toBe('Ferie')
        ->and($dispatched[1]->name)->toBe('Senza nome (1)');
});

test('delete removes the configuration and dispatches PromptConfigurationDeleted', function () {
    $configurations = new InMemoryPromptConfigurationRepository;
    $events = new RecordingEventDispatcher;
    $service = new PromptConfigurationService($configurations, $events);
    $id = $service->save(new SavePromptConfigurationCommand('Ferie', 'prompt', 'tono', 'stile', fakePromptConfigurationActor()));

    $service->delete($id, fakePromptConfigurationActor());

    expect($configurations->has($id))->toBeFalse()
        ->and($events->hasDispatched(PromptConfigurationDeleted::class))->toBeTrue();
});
