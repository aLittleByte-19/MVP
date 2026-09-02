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

/** Separatore delle migliaia applicato a mano: il runtime dei test non ha la locale it-IT, `toLocaleString` vi darebbe "1284". */
export function formatCount(value: number): string {
  const negative = value < 0;
  const digits = Math.abs(Math.trunc(value)).toString();
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

  return negative ? `-${grouped}` : grouped;
}

/**
 * Formatta il valore di una metrica separando l'unita' dal numero, senza reinterpretare il dato.
 * L'unita' arriva sempre dal campo `unit` del contratto: solo il backend sa in che scala ha calcolato il valore.
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

/** `history` e' un flusso di ingresso, non la storia del totale: va presentato come "n nuovi oggi", mai come variazione. */
export function newToday(history: readonly number[] | undefined): number | null {
  if (!history || history.length === 0) {
    return null;
  }

  return history[history.length - 1] ?? null;
}

/** Con `max === min` (serie piatta) disegna a meta' altezza invece che sul bordo: dividere per zero suggerirebbe uno zero inesistente. */
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
