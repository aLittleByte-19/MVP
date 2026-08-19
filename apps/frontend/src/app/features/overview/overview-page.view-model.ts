import { type Signal, computed } from "@angular/core";
import type { Router } from "@angular/router";
import type { Metric, MvpState } from "../../../api/generated/model";
import type { MvpView } from "../../core/navigation/app-views";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { formatMetric, type MetricTone, newToday } from "../../shared/util/metrics";

/** Indicatore di qualità di un modulo, già formattato per la scheda. */
export interface QualityMetric {
  readonly label: string;
  readonly value: string;
  readonly unit: string | null;
  /** Lo stesso valore come numero, per le schede che lo collocano su una scala. */
  readonly numeric: number | null;
}

/**
 * Una priorità della Overview.
 *
 * `outOf` sceglie anche la forma della scheda: con un totale la priorità è una
 * quota del corpus ("23 sotto-documenti su 412"), senza è un conteggio con il
 * suo andamento. Sono due domande diverse e due schede diverse.
 */
export interface PriorityMetric {
  readonly key: string;
  readonly label: string;
  readonly value: number | null;
  readonly tone: MetricTone;
  readonly history: number[] | undefined;
  readonly outOf: number | null;
  /** Riga sotto il numero: gli ingressi di oggi. */
  readonly context: string | null;
}

/**
 * ViewModel (Presentation Model, Fowler) della Overview: non tocca il DOM
 * né l'infrastruttura di rendering di Angular (niente `@Component`,
 * `inject()`, decoratori, injection context, `document`/`window` diretti)
 * — le dipendenze arrivano dal costruttore, non da `inject()`, quindi la
 * classe è istanziabile con `new` e testabile senza `TestBed`.
 * `OverviewPage` (la View) resta l'unico punto accoppiato ad Angular e al
 * DOM: si procura le dipendenze con `inject()`, costruisce questa istanza
 * e le passa `scrollToElement` (vedi `shared/util/scroll.ts`) come
 * funzione, cosicché il ViewModel possa *chiedere* uno scroll dopo la
 * navigazione senza *eseguirlo* direttamente.
 *
 * `error`, `loading` e `reload()` sono pass-through sullo store: il template
 * legge solo `vm.*`, come già fanno Copilot e Assistant (ADR 0011). Prima
 * questa era l'unica pagina che raggiungeva lo store dal markup.
 */
export class OverviewPageViewModel {
  readonly communications: Signal<MvpState["assistant"]["history"]> = computed(() => this.store.history());
  readonly error: Signal<string | null> = computed(() => this.store.error());
  readonly loading: Signal<boolean> = computed(() => this.store.loading());

  /**
   * Le tre metriche su cui si agisce, non un riassunto di tutte.
   *
   * I conteggi descrittivi vivono nelle pagine dei rispettivi moduli: qui
   * comparivano gli stessi valori due volte, una come priorità e una nei
   * pannelli sottostanti, e una terza volta dentro Co-Pilot.
   */
  readonly priorities: Signal<PriorityMetric[]> = computed(() => {
    const subDocuments = this.store.metricEntry("copilot.sub_documents");
    const total = typeof subDocuments?.value === "number" ? subDocuments.value : null;

    return [
      this.priority("copilot.needs_review", "Da verificare", "watch", total),
      this.priority("copilot.quarantined", "In quarantena", "alert", total),
      this.priority("assistant.drafts", "Bozze da valutare", "neutral", null)
    ];
  });

  /**
   * Un solo indicatore di qualità per modulo, con rimando alla pagina che ne
   * porta il dettaglio: è la ripetizione voluta fra sintesi e dettaglio, non
   * la copia dei conteggi che questa pagina mostrava prima.
   */
  readonly assistantQuality: Signal<QualityMetric | null> = computed(() =>
    this.quality("assistant.rating_average")
  );

  readonly copilotQuality: Signal<QualityMetric | null> = computed(() =>
    this.quality("copilot.confident_fields")
  );

  /** Numero di sotto-documenti in quarantena, per decidere se mostrare la segnalazione. */
  readonly quarantined: Signal<number> = computed(() => {
    const entry = this.store.metricEntry("copilot.quarantined");

    return typeof entry?.value === "number" ? entry.value : 0;
  });

  constructor(
    private readonly store: MvpStateStore,
    private readonly router: Router,
    private readonly scrollTo: (elementId: string) => void
  ) {}

  navigate(view: MvpView, targetId: string): void {
    void this.router.navigate([view]).then(() => this.scrollTo(targetId));
  }

  reload(): void {
    this.store.reload();
  }

  private quality(key: string): QualityMetric | null {
    const entry = this.store.metricEntry(key);

    if (entry === null) {
      return null;
    }

    const formatted = formatMetric(entry);

    return {
      label: entry.label,
      value: formatted.value,
      unit: formatted.unit,
      numeric: typeof entry.value === "number" ? entry.value : numericOf(formatted.value)
    };
  }

  /**
   * La serie è un flusso di ingresso, non la storia del totale (schema
   * `Metric` in OpenAPI): si dice "nuovi oggi", mai "rispetto a ieri".
   */
  private contextFor(entry: Metric | null): string | null {
    if (entry === null) {
      return null;
    }

    const today = newToday(entry.history);

    if (today === null || today <= 0) {
      return null;
    }

    return today === 1 ? "1 nuovo oggi" : `${today} nuovi oggi`;
  }

  /**
   * Totale di riferimento accanto al valore ("23/412"): vale solo per i
   * conteggi, e non prima che lo stato sia stato caricato.
   */
  private outOfFor(entry: Metric | null, outOf: number | null): number | null {
    return entry !== null && outOf !== null && outOf > 0 && typeof entry.value === "number"
      ? outOf
      : null;
  }

  /**
   * Conteggi sull'intero tenant, non sulle finestre di elenco: `history` si
   * ferma a 10 e `documents` a 40, quindi ricalcolarli qui darebbe numeri
   * silenziosamente sbagliati appena i dati superano quelle soglie.
   */
  private priority(key: string, label: string, tone: MetricTone, outOf: number | null): PriorityMetric {
    const entry = this.store.metricEntry(key);

    return {
      key,
      label,
      // `null` finché lo stato non è caricato: uno zero verrebbe letto come
      // un conteggio reale.
      value: typeof entry?.value === "number" ? entry.value : null,
      tone,
      history: entry?.history,
      outOf: this.outOfFor(entry, outOf),
      context: this.contextFor(entry)
    };
  }
}

/** Il numero dietro una media già formattata per la lettura ("4,3"). */
function numericOf(display: string): number | null {
  const parsed = Number(display.replace(",", "."));

  return Number.isFinite(parsed) ? parsed : null;
}
