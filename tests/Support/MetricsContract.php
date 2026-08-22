<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Estrae i riferimenti PromQL usati da dashboard Grafana e alert rule, per
 * confrontarli con cio' che l'applicazione emette davvero.
 *
 * Serve perche' nessun controllo esistente copre questo confine: `promtool
 * check config` valida la sintassi delle regole ma non l'esistenza delle
 * label, e il PromQL dentro i JSON delle dashboard non e' validato da niente.
 * E' cosi' che sono passati inosservati un alert che non poteva scattare
 * (label disgiunte ai due lati di `and`) e pannelli che interrogavano nomi di
 * metrica inesistenti.
 *
 * Solo i nomi con prefisso `mvp_` sono in perimetro: e' una regola sola, senza
 * allow-list da mantenere, e tiene fuori per costruzione traefik_*, otelcol_*,
 * `up` e ogni funzione o keyword PromQL.
 */
class MetricsContract
{
    /**
     * Espressioni PromQL di dashboard e regole. Le query Loki sono escluse dal
     * discriminatore `datasource.type`, che ogni target delle dashboard porta.
     *
     * @return list<array{source: string, expr: string}>
     */
    public static function promqlExpressions(): array
    {
        $expressions = [];

        foreach (glob(base_path('docker/grafana/dashboards/*.json')) ?: [] as $path) {
            $dashboard = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $name = basename($path);

            foreach (self::panels($dashboard['panels'] ?? []) as $panel) {
                foreach ($panel['targets'] ?? [] as $target) {
                    $type = $target['datasource']['type'] ?? $panel['datasource']['type'] ?? null;

                    if ($type !== 'prometheus' || ! is_string($target['expr'] ?? null)) {
                        continue;
                    }

                    $expressions[] = [
                        'source' => $name.' » '.($panel['title'] ?? 'senza titolo'),
                        'expr' => $target['expr'],
                    ];
                }
            }
        }

        foreach (glob(base_path('docker/prometheus/rules/*.yml')) ?: [] as $path) {
            $rules = Yaml::parseFile($path);
            $name = basename($path);

            foreach ($rules['groups'] ?? [] as $group) {
                foreach ($group['rules'] ?? [] as $rule) {
                    if (is_string($rule['expr'] ?? null)) {
                        $expressions[] = [
                            'source' => $name.' » '.($rule['alert'] ?? $rule['record'] ?? 'senza nome'),
                            'expr' => $rule['expr'],
                        ];
                    }
                }
            }
        }

        return $expressions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $panels
     * @return list<array<string, mixed>>
     */
    private static function panels(array $panels): array
    {
        $flat = [];

        foreach ($panels as $panel) {
            $flat[] = $panel;

            if (is_array($panel['panels'] ?? null)) {
                $flat = array_merge($flat, self::panels($panel['panels']));
            }
        }

        return $flat;
    }

    /**
     * Nomi di metrica `mvp_*` citati da un'espressione.
     *
     * @return list<string>
     */
    public static function metricNames(string $expr): array
    {
        preg_match_all('/\bmvp_[a-z0-9_]+/i', $expr, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Label usate su ogni metrica `mvp_*`: sia nei matcher `{k="v"}` sia nelle
     * clausole di aggregazione `by (...)`.
     *
     * Le `by (...)` non sono attribuibili a una metrica specifica quando
     * l'espressione ne cita piu' d'una, quindi sono restituite a parte e il
     * test le verifica come "esiste almeno una metrica dell'espressione che
     * dichiara questa label".
     *
     * @return array{matchers: array<string, list<array{label: string, operator: string, value: string}>>, byLabels: list<string>}
     */
    public static function labelsUsed(string $expr): array
    {
        $matchers = [];

        preg_match_all('/\b(mvp_[a-z0-9_]+)\s*\{([^}]*)\}/i', $expr, $found, PREG_SET_ORDER);

        foreach ($found as $match) {
            $metric = $match[1];
            preg_match_all('/([a-z_][a-z0-9_]*)\s*(=~|!~|!=|=)\s*"([^"]*)"/i', $match[2], $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $matchers[$metric][] = [
                    'label' => $pair[1],
                    'operator' => $pair[2],
                    'value' => $pair[3],
                ];
            }
        }

        $byLabels = [];
        preg_match_all('/\bby\s*\(([^)]*)\)/i', $expr, $groupings);

        foreach ($groupings[1] ?? [] as $group) {
            foreach (explode(',', $group) as $label) {
                $label = trim($label);

                // `le` appartiene al formato histogram, non alla dichiarazione
                // delle label di dominio.
                if ($label !== '' && $label !== 'le') {
                    $byLabels[] = $label;
                }
            }
        }

        return [
            'matchers' => $matchers,
            'byLabels' => array_values(array_unique($byLabels)),
        ];
    }

    /**
     * Nomi di metrica presenti nell'esposizione, con le label dichiarate da
     * ogni riga `# TYPE`.
     *
     * @return array<string, string> nome => tipo
     */
    public static function declaredTypes(string $exposition): array
    {
        preg_match_all('/^# TYPE (\S+) (\S+)$/m', $exposition, $matches, PREG_SET_ORDER);

        $types = [];

        foreach ($matches as $match) {
            $types[$match[1]] = $match[2];
        }

        return $types;
    }
}
