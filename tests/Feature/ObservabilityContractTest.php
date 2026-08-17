<?php

use App\Mvp\Observability\DomainMetricCatalog;
use App\Mvp\Workflow\Services\WorkflowTaskRegistry;
use Tests\Support\MetricsContract;

/**
 * Lega cio' che il codice emette a cio' che dashboard e alert interrogano.
 *
 * Ogni difetto chiuso in questa tornata sarebbe stato intercettato qui:
 * una metrica emessa con `reason` e interrogata per `state_machine`, un
 * pannello che cita un nome inesistente, un enum cambiato senza aggiornare i
 * matcher. `promtool check config` non li vede (valida la sintassi, non le
 * label) e il PromQL dentro i JSON delle dashboard non e' validato da nulla.
 */

/**
 * Metriche dichiarate ma volutamente non graficate. Ogni voce ha un motivo
 * esplicito: aggiungerne una deve essere una decisione, non una dimenticanza.
 *
 * @var array<string, string>
 */
const ALLOWED_UNGRAPHED = [
    // Diagnostica dell'esposizione stessa: la si guarda quando si indaga un
    // buco nei dati, non da un pannello.
    'metrics_collection_failures_total' => 'diagnostica interna dell_exporter',
    // Emessa a ogni scrape come metadato, usata da WorkerDown via absent().
    'app_info' => 'metadato statico, interrogato solo da WorkerDown',
];

test('ogni metrica del catalogo e resa da /internal/metrics', function () {
    $exposition = $this->get('/internal/metrics')->assertOk()->getContent();
    $types = MetricsContract::declaredTypes((string) $exposition);

    $missing = [];
    $wrongType = [];

    foreach (DomainMetricCatalog::all() as $definition) {
        $name = 'mvp_'.$definition->name;

        if (! array_key_exists($name, $types)) {
            $missing[] = $name;

            continue;
        }

        if ($types[$name] !== $definition->type) {
            $wrongType[] = "{$name}: catalogo {$definition->type}, esposizione {$types[$name]}";
        }
    }

    expect($missing)->toBe([], "A catalogo ma non esposte:\n - ".implode("\n - ", $missing));
    expect($wrongType)->toBe([], "Tipo divergente fra catalogo ed esposizione:\n - ".implode("\n - ", $wrongType));
});

test('ogni metrica citata da dashboard e alert esiste nel catalogo', function () {
    $known = [];

    foreach (DomainMetricCatalog::all() as $definition) {
        if ($definition->type === 'summary') {
            $known['mvp_'.$definition->name.'_sum'] = true;
            $known['mvp_'.$definition->name.'_count'] = true;

            continue;
        }

        $known['mvp_'.$definition->name] = true;
    }

    // Istogramma HTTP: reso da httpMetrics(), non dal catalogo di dominio.
    foreach (['mvp_http_requests_total', 'mvp_http_request_duration_seconds_bucket', 'mvp_http_request_duration_seconds_sum', 'mvp_http_request_duration_seconds_count'] as $name) {
        $known[$name] = true;
    }

    $unknown = [];

    foreach (MetricsContract::promqlExpressions() as $expression) {
        foreach (MetricsContract::metricNames($expression['expr']) as $metric) {
            if (! isset($known[$metric])) {
                $unknown[] = "{$expression['source']}: {$metric}";
            }
        }
    }

    expect($unknown)->toBe([], "Metriche interrogate ma inesistenti:\n - ".implode("\n - ", $unknown));
});

test('ogni label usata da dashboard e alert e dichiarata sulla metrica', function () {
    $declared = [];

    foreach (DomainMetricCatalog::all() as $definition) {
        $names = $definition->type === 'summary'
            ? ['mvp_'.$definition->name.'_sum', 'mvp_'.$definition->name.'_count']
            : ['mvp_'.$definition->name];

        foreach ($names as $name) {
            $declared[$name] = $definition->labelNames;
        }
    }

    $declared['mvp_http_requests_total'] = ['method', 'route', 'status'];
    $declared['mvp_http_request_duration_seconds_bucket'] = ['le', 'method', 'route'];
    $declared['mvp_http_request_duration_seconds_sum'] = ['method', 'route'];
    $declared['mvp_http_request_duration_seconds_count'] = ['method', 'route'];

    $violations = [];

    foreach (MetricsContract::promqlExpressions() as $expression) {
        $used = MetricsContract::labelsUsed($expression['expr']);

        foreach ($used['matchers'] as $metric => $matchers) {
            if (! isset($declared[$metric])) {
                continue; // gia' coperto dal test sui nomi
            }

            foreach ($matchers as $matcher) {
                if (! in_array($matcher['label'], $declared[$metric], true)) {
                    $violations[] = "{$expression['source']}: {$metric} non dichiara la label {$matcher['label']}";
                }
            }
        }

        // Su un'espressione con piu' metriche la clausola `by (...)` non e'
        // attribuibile a una sola: basta che una la dichiari.
        $metrics = array_values(array_filter(
            MetricsContract::metricNames($expression['expr']),
            fn (string $metric): bool => isset($declared[$metric]),
        ));

        foreach ($used['byLabels'] as $label) {
            $covered = false;

            foreach ($metrics as $metric) {
                if (in_array($label, $declared[$metric], true)) {
                    $covered = true;
                    break;
                }
            }

            if ($metrics !== [] && ! $covered) {
                $violations[] = "{$expression['source']}: nessuna metrica dichiara la label di aggregazione {$label}";
            }
        }
    }

    expect($violations)->toBe([], "Label interrogate ma non emesse:\n - ".implode("\n - ", $violations));
});

test('i valori dei matcher esatti appartengono agli enum dichiarati', function () {
    $enumerated = [];

    foreach (DomainMetricCatalog::all() as $definition) {
        if ($definition->enumeratedLabels !== []) {
            $enumerated['mvp_'.$definition->name] = $definition->enumeratedLabels;
        }
    }

    $violations = [];

    foreach (MetricsContract::promqlExpressions() as $expression) {
        foreach (MetricsContract::labelsUsed($expression['expr'])['matchers'] as $metric => $matchers) {
            foreach ($matchers as $matcher) {
                // Solo l'uguaglianza esatta: sulle regex i falsi positivi
                // sarebbero piu' dei difetti trovati.
                if ($matcher['operator'] !== '=') {
                    continue;
                }

                $values = $enumerated[$metric][$matcher['label']] ?? null;

                if ($values !== null && ! in_array($matcher['value'], $values, true)) {
                    $violations[] = sprintf(
                        '%s: %s{%s="%s"} non e\' fra i valori ammessi (%s)',
                        $expression['source'],
                        $metric,
                        $matcher['label'],
                        $matcher['value'],
                        implode(', ', $values),
                    );
                }
            }
        }
    }

    expect($violations)->toBe([], "Valori di label inesistenti:\n - ".implode("\n - ", $violations));
});

test('ogni metrica del catalogo e osservata da almeno una dashboard o regola', function () {
    $referenced = [];

    foreach (MetricsContract::promqlExpressions() as $expression) {
        foreach (MetricsContract::metricNames($expression['expr']) as $metric) {
            $referenced[$metric] = true;
        }
    }

    $orphans = [];

    foreach (DomainMetricCatalog::all() as $definition) {
        if (array_key_exists($definition->name, ALLOWED_UNGRAPHED)) {
            continue;
        }

        $names = $definition->type === 'summary'
            ? ['mvp_'.$definition->name.'_sum', 'mvp_'.$definition->name.'_count']
            : ['mvp_'.$definition->name];

        foreach ($names as $name) {
            if (! isset($referenced[$name])) {
                $orphans[] = $name;
            }
        }
    }

    expect($orphans)->toBe([], "Metriche emesse che nessuno guarda:\n - ".implode("\n - ", $orphans));
});

test('i task type del catalogo coincidono con quelli registrati', function () {
    $registered = app(WorkflowTaskRegistry::class)->taskTypes();
    sort($registered);

    expect($registered)->toBe(
        DomainMetricCatalog::TASK_TYPES,
        'DomainMetricCatalog::TASK_TYPES e WorkflowTaskRegistry divergono: le serie a zero non coprirebbero piu\' tutti i task.'
    );
});

test('nessun counter usa un nome riservato ad altre forme di metrica', function () {
    $violations = [];

    foreach (DomainMetricCatalog::all() as $definition) {
        $isCounter = $definition->type === 'counter';
        $endsWithTotal = str_ends_with($definition->name, '_total');

        // `_sum`/`_count` su un counter fanno appendere `_total` dal
        // prometheusexporter del Collector, che e' come i pannelli OCR sono
        // rimasti vuoti: il nome interrogato non esisteva piu'.
        if ($isCounter && (str_ends_with($definition->name, '_sum') || str_ends_with($definition->name, '_count'))) {
            $violations[] = "{$definition->name}: un counter non puo' chiamarsi _sum/_count, usa una famiglia summary";
        }

        if ($isCounter && ! $endsWithTotal) {
            $violations[] = "{$definition->name}: un counter deve terminare in _total";
        }

        if ($definition->type === 'gauge' && $endsWithTotal) {
            $violations[] = "{$definition->name}: _total e' riservato ai counter";
        }
    }

    expect($violations)->toBe([], "Nomi fuori convenzione Prometheus:\n - ".implode("\n - ", $violations));
});
