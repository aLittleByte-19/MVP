/**
 * Geometria dei grafici delle schede metrica.
 *
 * Sta fuori dai componenti perche' e' aritmetica pura — coordinate dentro un
 * viewBox — e come tale si prova senza montare nulla. I componenti si limitano
 * a chiamarla e a metterne il risultato dentro un `<svg>`.
 */

/** Una barra della serie giornaliera, gia' collocata nel viewBox. */
export interface Bar {
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
  /** L'ultima barra e' oggi: si distingue dalle altre. */
  readonly isLast: boolean;
}

/**
 * Barre di una serie, una per giorno.
 *
 * Le barre hanno tutte la stessa larghezza e sono separate da un vuoto
 * proporzionale: con sette valori la lettura e' "questi sono i giorni", non
 * "questa e' una curva". Un valore a zero conserva un filo di altezza, cosi'
 * il giorno esiste anche quando non e' successo nulla.
 */
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

/** Un intervallo della densita': fin dove arriva e quante corse ci cadono. */
export interface DensityBucket {
  readonly upTo: number;
  readonly count: number;
}

/** Il tracciato della densita' piu' gli estremi dell'asse dei tempi. */
export interface DensityShape {
  /** Spezzata morbida che passa per i punti, come una funzione continua. */
  readonly line: string;
  /** Lo stesso tracciato chiuso sulla linea di base, per l'area sotto la curva. */
  readonly area: string;
  /** Ascisse delle tacche, in unita' del viewBox. */
  readonly ticks: readonly { readonly x: number; readonly label: number }[];
}

/**
 * Densita' delle durate come curva continua.
 *
 * I punti sono i centri degli intervalli, non i loro estremi: un intervallo
 * "fino a 30s" rappresenta le corse fra 20 e 30, e il suo peso va messo in
 * mezzo. La curva parte e finisce sulla linea di base perche' e' una densita':
 * fuori dall'intervallo misurato non c'e' nulla, e chiuderla altrove
 * suggerirebbe corse che non esistono.
 *
 * Il tracciato passa da Catmull-Rom a Bezier: e' l'interpolazione che tiene la
 * curva dentro i punti misurati senza le oscillazioni di una spline naturale.
 * «Dentro» vale per i punti, non per le tangenti: fra un intervallo vuoto e un
 * picco la curva scavalca comunque il valore massimo, e con il picco appoggiato
 * al bordo superiore usciva dal riquadro e si vedeva tagliata. Per questo il
 * massimo si ferma un poco sotto il bordo e il tracciato resta comunque
 * limitato all'altezza disponibile.
 */
export function densityShape(
  buckets: readonly DensityBucket[],
  width: number,
  height: number,
  tickCount = 6
): DensityShape | null {
  if (buckets.length < 2) {
    return null;
  }

  const step = buckets[0]!.upTo;
  const span = buckets[buckets.length - 1]!.upTo;
  const peak = Math.max(...buckets.map((bucket) => bucket.count), 1);
  // Aria sopra il picco: e' lo spazio in cui la curva puo' scavalcarlo senza
  // finire fuori dal riquadro.
  const headroom = height * 0.18;

  const x = (seconds: number): number => (seconds / span) * width;
  const y = (count: number): number => height - (count / peak) * (height - headroom);

  const points: [number, number][] = [
    [0, height],
    ...buckets.map((bucket): [number, number] => [x(bucket.upTo - step / 2), y(bucket.count)]),
    [width, height]
  ];

  const ticks = Array.from({ length: tickCount + 1 }, (_, index) => {
    const seconds = (span / tickCount) * index;

    return { x: round(x(seconds)), label: Math.round(seconds) };
  });

  const line = smoothPath(points, { min: 0, max: height });

  return { line, area: `${line} L${round(width)},${round(height)} Z`, ticks };
}

/**
 * Percorso morbido attraverso i punti (Catmull-Rom convertita in Bezier
 * cubiche). Le tangenti sono un sesto della distanza fra i due punti vicini:
 * e' il fattore che riproduce la spline uniforme.
 */
export function smoothPath(
  points: readonly [number, number][],
  verticalBounds?: { readonly min: number; readonly max: number }
): string {
  if (points.length === 0) {
    return "";
  }

  // Le tangenti possono spingere il tracciato oltre i punti che collega: dove
  // c'e' un riquadro da rispettare si tengono dentro i suoi bordi.
  const bound = (value: number): number =>
    verticalBounds ? Math.min(verticalBounds.max, Math.max(verticalBounds.min, value)) : value;

  const segments = [`M${round(points[0]![0])},${round(points[0]![1])}`];

  for (let index = 0; index < points.length - 1; index++) {
    const previous = points[index - 1] ?? points[index]!;
    const start = points[index]!;
    const end = points[index + 1]!;
    const next = points[index + 2] ?? end;

    const control1: [number, number] = [
      start[0] + (end[0] - previous[0]) / 6,
      bound(start[1] + (end[1] - previous[1]) / 6)
    ];
    const control2: [number, number] = [end[0] - (next[0] - start[0]) / 6, bound(end[1] - (next[1] - start[1]) / 6)];

    segments.push(
      `C${round(control1[0])},${round(control1[1])} ${round(control2[0])},${round(control2[1])} ${round(end[0])},${round(end[1])}`
    );
  }

  return segments.join(" ");
}

/** Un segmento della ripartizione, in percentuale della larghezza. */
export interface Segment {
  readonly label: string;
  readonly value: number;
  readonly percent: number;
  /** Indice nella scala categorica (1-5), per il colore. */
  readonly tone: number;
}

/**
 * Ripartizione di un totale fra le sue parti.
 *
 * La scala e' categorica e monocromatica (tonalita' del primario): le fasi di
 * una pipeline non esprimono un giudizio, quindi verde e rosso direbbero
 * qualcosa che il dato non dice.
 */
export function segments(parts: readonly { label: string; value: number }[]): Segment[] {
  const total = parts.reduce((sum, part) => sum + part.value, 0);

  if (total <= 0) {
    return [];
  }

  return parts.map((part, index) => ({
    label: part.label,
    value: part.value,
    percent: round((part.value / total) * 100),
    tone: Math.min(index + 1, 5)
  }));
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

/**
 * Archi di un anello segmentato, uno per parte.
 *
 * Ogni arco e' un cerchio intero con un tratteggio che ne lascia visibile solo
 * la propria fetta, spostata dall'offset: e' il modo di disegnare una corona
 * segmentata con elementi SVG di base, senza calcolare archi a mano ne'
 * dipendere da una libreria.
 */
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
