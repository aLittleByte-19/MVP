import { dailyBars, ringArcs, ringDash, starFills } from "./charts";

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

describe("ringArcs", () => {
  const parts = [
    { label: "a", value: 1, tone: "warning" },
    { label: "b", value: 3, tone: "info" }
  ];

  it("da' a ciascuna parte la sua fetta e la fa partire dove finisce la precedente", () => {
    const arcs = ringArcs(parts, 10);
    const circumference = 2 * Math.PI * 10;

    expect(arcs).toHaveLength(2);
    expect(Number(arcs[0]!.dash.split(" ")[0])).toBeCloseTo(circumference / 4, 1);
    expect(arcs[0]!.offset).toBe(0);
    expect(arcs[1]!.offset).toBeCloseTo(-circumference / 4, 1);
  });

  it("lascia fuori le parti a zero, che sarebbero archi invisibili con una voce in legenda", () => {
    const arcs = ringArcs([...parts, { label: "vuota", value: 0 }], 10);

    expect(arcs.map((arc) => arc.label)).toEqual(["a", "b"]);
  });

  it("non produce nulla quando non c'e' niente da ripartire", () => {
    expect(ringArcs([], 10)).toEqual([]);
    expect(ringArcs([{ label: "a", value: 0 }], 10)).toEqual([]);
  });
});
