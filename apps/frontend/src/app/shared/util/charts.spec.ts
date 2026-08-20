import { dailyBars, densityShape, ringDash, segments, smoothPath, starFills } from "./charts";

describe("dailyBars", () => {
  it("scala le barre sul giorno piu' alto e marca l'ultimo", () => {
    const bars = dailyBars([1, 2, 4], 300, 40);

    expect(bars).toHaveLength(3);
    expect(bars[2]?.isLast).toBe(true);
    expect(bars[2]!.height).toBeGreaterThan(bars[0]!.height);
  });

  it("lascia un filo di altezza ai giorni a zero", () => {
    // Un giorno senza elementi esiste comunque: una barra invisibile lo
    // farebbe sembrare un buco nella serie.
    const bars = dailyBars([0, 5], 200, 40);

    expect(bars[0]!.height).toBeGreaterThan(0);
  });

  it("non produce nulla su una serie vuota", () => {
    expect(dailyBars([], 200, 40)).toEqual([]);
  });
});

describe("densityShape", () => {
  const buckets = [
    { upTo: 10, count: 2 },
    { upTo: 20, count: 9 },
    { upTo: 30, count: 3 }
  ];

  it("chiude la curva sulla linea di base", () => {
    // Fuori dall'intervallo misurato non c'e' nulla: chiuderla altrove
    // suggerirebbe corse che non esistono.
    const shape = densityShape(buckets, 100, 50)!;

    expect(shape.line.startsWith("M0,50")).toBe(true);
    expect(shape.area.endsWith("Z")).toBe(true);
  });

  it("distribuisce le tacche fino all'ultimo intervallo", () => {
    const shape = densityShape(buckets, 100, 50, 3)!;

    expect(shape.ticks.map((tick) => tick.label)).toEqual([0, 10, 20, 30]);
    expect(shape.ticks[3]!.x).toBe(100);
  });

  it("non disegna nulla con un intervallo solo", () => {
    expect(densityShape([{ upTo: 10, count: 1 }], 100, 50)).toBeNull();
  });
});

describe("smoothPath", () => {
  it("parte dal primo punto e produce una curva per ogni segmento", () => {
    const path = smoothPath([
      [0, 0],
      [10, 10],
      [20, 0]
    ]);

    expect(path.startsWith("M0,0")).toBe(true);
    expect(path.match(/C/g)).toHaveLength(2);
  });

  it("resta vuoto senza punti", () => {
    expect(smoothPath([])).toBe("");
  });
});

describe("segments", () => {
  it("converte le parti in percentuali e assegna la scala categorica", () => {
    const parts = segments([
      { label: "OCR", value: 30 },
      { label: "Estrazione", value: 10 }
    ]);

    expect(parts[0]).toEqual({ label: "OCR", value: 30, percent: 75, tone: 1 });
    expect(parts[1]!.tone).toBe(2);
  });

  it("si ferma alla quinta tonalita', oltre la barra non si legge piu'", () => {
    const parts = segments(Array.from({ length: 7 }, (_, index) => ({ label: `f${index}`, value: 1 })));

    expect(parts[6]!.tone).toBe(5);
  });

  it("non produce nulla quando il totale e' zero", () => {
    expect(segments([{ label: "vuota", value: 0 }])).toEqual([]);
  });
});

describe("ringDash", () => {
  it("divide la circonferenza fra la parte piena e il resto", () => {
    const { filled, rest } = ringDash(25, 10);

    expect(filled + rest).toBeCloseTo(2 * Math.PI * 10, 1);
    expect(filled).toBeCloseTo((2 * Math.PI * 10) / 4, 1);
  });

  it("tiene la quota dentro i suoi estremi", () => {
    expect(ringDash(140, 10).rest).toBe(0);
    expect(ringDash(-10, 10).filled).toBe(0);
  });
});

describe("starFills", () => {
  it("riempie le stelle intere e lascia la frazione all'ultima", () => {
    expect(starFills(4.3)).toEqual([1, 1, 1, 1, expect.closeTo(0.3, 5)]);
  });

  it("non riempie nulla a zero", () => {
    expect(starFills(0)).toEqual([0, 0, 0, 0, 0]);
  });
});
