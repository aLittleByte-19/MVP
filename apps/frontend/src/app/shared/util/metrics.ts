import type { Metric } from "../../../api/generated/model";

/** Rilievo visivo di una metrica, indipendente dal dominio che la usa. */
export type MetricTone = "neutral" | "ok" | "watch" | "alert";

/** Valore e unita' separati, cosi' la card puo' dare loro peso tipografico diverso. */
export interface FormattedMetric {
  readonly value: string;
  readonly unit: string | null;
}

/** Placeholder di un valore non disponibile: diverso da zero, che e' un dato. */
export const NOT_AVAILABLE = "—";

/**
 * Separatore delle migliaia italiano, applicato a mano invece che con `Intl`.
 *
 * Non e' preferenza stilistica: il runtime dei test non ha la locale it-IT
 * (build di Node senza ICU completo) e `toLocaleString("it-IT")` vi restituisce
 * "1284" invece di "1.284". Con una formattazione esplicita il numero e'
 * identico ovunque, e il test verifica davvero quello che l'utente vedra'.
 */
export function formatCount(value: number): string {
  const negative = value < 0;
  const digits = Math.abs(Math.trunc(value)).toString();
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

  return negative ? `-${grouped}` : grouped;
}

/**
 * Formatta il valore di una metrica separando l'unita' dal numero.
 *
 * Il backend invia gia' una stringa per le medie (`4.3`, oppure `—` quando non
 * ce n'e' una) e un intero per i conteggi: qui si distingue solo la resa, senza
 * reinterpretare il dato. I conteggi passano da `formatCount` perche' `1284` va
 * letto `1.284` in italiano, e il separatore decimale diventa la virgola.
 *
 * L'unita' arriva dal contratto (campo `unit`). Prima era una tabella chiave →
 * unita' scritta qui, con dentro una sola metrica: alla seconda misura non
 * pura — secondi, minuti, percentuali — sarebbe andata fuori sincrono col
 * backend, che e' l'unico a sapere in che scala ha calcolato il valore.
 */
export function formatMetric(metric: Metric): FormattedMetric {
  const raw = metric.value;
  const unit = metric.unit ?? null;

  if (typeof raw === "number") {
    return { value: formatCount(raw), unit };
  }

  if (raw === NOT_AVAILABLE || raw.trim() === "") {
    // Senza valore l'unita' non ha nulla da qualificare: "— %" e' rumore.
    return { value: NOT_AVAILABLE, unit: null };
  }

  return { value: raw.replace(".", ","), unit };
}

/**
 * Elementi entrati oggi, cioe' l'ultimo punto della serie.
 *
 * `history` e' un flusso di ingresso, non la storia del totale (vedi lo schema
 * `Metric` in OpenAPI): va quindi presentato come "n nuovi oggi" e mai come
 * variazione del valore mostrato.
 */
export function newToday(history: readonly number[] | undefined): number | null {
  if (!history || history.length === 0) {
    return null;
  }

  return history[history.length - 1] ?? null;
}

/**
 * Punti di una polilinea che ricalca la serie, gia' scalati nel viewBox.
 *
 * Una serie piatta viene disegnata a meta' altezza invece che sul bordo: con
 * `max === min` la normalizzazione dividerebbe per zero, e una linea appoggiata
 * al fondo suggerirebbe uno zero che non c'e'.
 */
export function sparklinePoints(
  history: readonly number[] | undefined,
  width: number,
  height: number,
  padding = 3
): string | null {
  if (!history || history.length < 2) {
    return null;
  }

  const max = Math.max(...history);
  const min = Math.min(...history);
  const span = max - min;
  const usableHeight = height - padding * 2;
  const step = (width - padding * 2) / (history.length - 1);

  return history
    .map((point, index) => {
      const ratio = span === 0 ? 0.5 : (point - min) / span;
      const x = padding + step * index;
      const y = padding + usableHeight - ratio * usableHeight;

      return `${round(x)},${round(y)}`;
    })
    .join(" ");
}

/** Ultimo punto della polilinea, per evidenziare dove la serie arriva. */
export function sparklineEnd(
  history: readonly number[] | undefined,
  width: number,
  height: number,
  padding = 3
): { x: number; y: number } | null {
  const points = sparklinePoints(history, width, height, padding);

  if (points === null) {
    return null;
  }

  const [x, y] = points.split(" ").slice(-1)[0]!.split(",").map(Number);

  return { x: x!, y: y! };
}

function round(value: number): number {
  return Math.round(value * 10) / 10;
}
