/** Geometria dei grafici delle schede metrica: aritmetica pura, testabile senza montare nulla. */

/** Una barra della serie giornaliera, gia' collocata nel viewBox. */
export interface Bar {
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
  /** L'ultima barra e' oggi: si distingue dalle altre. */
  readonly isLast: boolean;
}

/** Barre di uguale larghezza e vuoto proporzionale (letture come giorni distinti, non una curva); un valore a zero conserva un filo di altezza. */
export function dailyBars(series: readonly number[], width: number, height: number): Bar[] {
  if (series.length === 0) {
    return [];
  }

  const peak = Math.max(...series, 1);
  const slot = width / series.length;
  const barWidth = slot * 0.58;

  return series.map((value, index) => {
    const barHeight = Math.max(2, (value / peak) * (height - 2));

    return {
      x: round(index * slot + (slot - barWidth) / 2),
      y: round(height - barHeight),
      width: round(barWidth),
      height: round(barHeight),
      isLast: index === series.length - 1
    };
  });
}

/** Un arco dell'anello: quanto e' lungo, dove comincia, di che tono. */
export interface RingArc {
  readonly label: string;
  readonly value: number;
  readonly tone: string;
  /** `stroke-dasharray`: lunghezza dell'arco e resto della circonferenza. */
  readonly dash: string;
  /** `stroke-dashoffset`: da dove parte, cioe' quanto lo precede. */
  readonly offset: number;
}

/** Ogni arco e' un cerchio intero con tratteggio che ne lascia visibile solo la propria fetta, spostata dall'offset: corona segmentata senza libreria SVG esterna. */
export function ringArcs(
  parts: readonly { label: string; value: number; tone?: string }[],
  radius: number
): RingArc[] {
  const total = parts.reduce((sum, part) => sum + part.value, 0);

  if (total <= 0) {
    return [];
  }

  const circumference = 2 * Math.PI * radius;
  let consumed = 0;

  return parts
    .filter((part) => part.value > 0)
    .map((part) => {
      const length = (circumference * part.value) / total;
      const arc: RingArc = {
        label: part.label,
        value: part.value,
        tone: part.tone ?? "neutral",
        dash: `${round(length)} ${round(circumference - length)}`,
        // `-0` non e' `0` per `Object.is`, e il primo arco parte proprio da li'.
        offset: consumed === 0 ? 0 : round(-consumed)
      };

      consumed += length;

      return arc;
    });
}

/**
 * Tratteggio di un anello: quanta circonferenza colorare e quanta lasciarne
 * scoperta. `stroke-dasharray` vuole due lunghezze, non una percentuale.
 */
export function ringDash(percent: number, radius: number): { readonly filled: number; readonly rest: number } {
  const circumference = 2 * Math.PI * radius;
  const filled = (circumference * Math.min(100, Math.max(0, percent))) / 100;

  return { filled: round(filled), rest: round(circumference - filled) };
}

/**
 * Riempimento di ciascuna stella, da 0 a 1. La stella parziale non si
 * arrotonda: 4,3 su 5 sono quattro stelle piene e tre decimi della quinta, ed
 * e' proprio quel resto a distinguere un 4,3 da un 4,5.
 */
export function starFills(value: number, count = 5): number[] {
  return Array.from({ length: count }, (_, index) => Math.max(0, Math.min(1, value - index)));
}

function round(value: number): number {
  return Math.round(value * 10) / 10;
}
