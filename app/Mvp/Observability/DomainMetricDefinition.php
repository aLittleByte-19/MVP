<?php

namespace App\Mvp\Observability;

/**
 * Dichiarazione di una singola metrica di dominio: nome, tipo, help e forma
 * delle label. Serve a rendere l'esposizione Prometheus *dichiarata* invece
 * che dedotta da cio' che il file di accumulo contiene per caso — senza,
 * una metrica mai emessa semplicemente non esiste, e una query come
 * `increase(...) == 0` non ha nulla su cui valutare.
 *
 * `labelNames` e' sempre ordinato alfabeticamente perche' e' cosi' che
 * MetricsRecorder ordina le label prima di scriverle (ksort in `increment()`):
 * il confronto fra dichiarato e osservato resta quindi un semplice `===`.
 */
final class DomainMetricDefinition
{
    /**
     * @param  list<string>  $labelNames  chiavi label ammesse, ordinate alfabeticamente
     * @param  array<string, list<string>>  $enumeratedLabels  valori noti a priori, per emettere le serie a 0
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $help,
        public readonly array $labelNames = [],
        public readonly array $enumeratedLabels = [],
    ) {}

    /**
     * Combinazioni di label da emettere anche quando non ancora osservate.
     * Vuoto quando almeno una label non e' enumerabile (i codici di errore AWS
     * non sono un insieme chiuso): li' la serie compare solo dopo il primo
     * evento reale, ed e' corretto cosi' — inventare valori produrrebbe serie
     * che non corrispondono a niente.
     *
     * @return list<array<string, string>>
     */
    public function seedLabelSets(): array
    {
        if ($this->labelNames === []) {
            return [[]];
        }

        if (array_keys($this->enumeratedLabels) !== $this->labelNames) {
            return [];
        }

        $combinations = [[]];

        foreach ($this->labelNames as $label) {
            $expanded = [];

            foreach ($combinations as $combination) {
                foreach ($this->enumeratedLabels[$label] as $value) {
                    $expanded[] = $combination + [$label => $value];
                }
            }

            $combinations = $expanded;
        }

        return $combinations;
    }
}
