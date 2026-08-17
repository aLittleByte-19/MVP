import type { Metric } from "../../../api/generated/model";
import { formatMetric, newToday, sparklineEnd, sparklinePoints } from "./metrics";

function metric(partial: Partial<Metric> & Pick<Metric, "key" | "value">): Metric {
  return { label: "Etichetta", ...partial } as Metric;
}

describe("formatMetric", () => {
  it("formatta i conteggi con il separatore delle migliaia italiano", () => {
    expect(formatMetric(metric({ key: "copilot.confident_fields", value: 1284 }))).toEqual({
      value: "1.284",
      unit: null
    });
  });

  it("separa l'unita' dalla media stelle, cosi' non pesa come un conteggio", () => {
    // "4.3" e "128" avevano la stessa resa pur essendo una media su 5 e un
    // conteggio: l'unita' e' l'unico elemento che li distingue a colpo d'occhio.
    expect(formatMetric(metric({ key: "assistant.rating_average", value: "4.3" }))).toEqual({
      value: "4,3",
      unit: "/ 5"
    });
  });

  it("riconosce il segnaposto del backend quando non ci sono valutazioni", () => {
    expect(formatMetric(metric({ key: "assistant.rating_average", value: "—" }))).toEqual({
      value: "—",
      unit: null
    });
  });

  it("lascia intatte le altre stringhe", () => {
    expect(formatMetric(metric({ key: "custom", value: "n/d" }))).toEqual({
      value: "n/d",
      unit: null
    });
  });
});

describe("newToday", () => {
  it("prende l'ultimo punto della serie, che e' il giorno corrente", () => {
    expect(newToday([1, 0, 4, 2, 0, 7, 3])).toBe(3);
  });

  it("vale null senza serie, cosi' la card non inventa un contesto", () => {
    expect(newToday(undefined)).toBeNull();
    expect(newToday([])).toBeNull();
  });
});

describe("sparklinePoints", () => {
  it("scala la serie dentro il viewBox rispettando il padding", () => {
    const points = sparklinePoints([0, 10], 100, 20, 2)!.split(" ");

    expect(points).toHaveLength(2);
    expect(points[0]).toBe("2,18");
    expect(points[1]).toBe("98,2");
  });

  it("disegna una serie piatta a meta' altezza, non sul fondo", () => {
    // Con max === min la normalizzazione dividerebbe per zero, e una linea
    // appoggiata al fondo suggerirebbe uno zero che non c'e'.
    const points = sparklinePoints([5, 5, 5], 100, 20, 2)!.split(" ");

    expect(points.every((point) => point.endsWith(",10"))).toBe(true);
  });

  it("non disegna nulla sotto i due punti", () => {
    expect(sparklinePoints([4], 100, 20)).toBeNull();
    expect(sparklinePoints(undefined, 100, 20)).toBeNull();
  });
});

describe("sparklineEnd", () => {
  it("restituisce l'ultimo punto, per evidenziare dove arriva la serie", () => {
    expect(sparklineEnd([0, 10], 100, 20, 2)).toEqual({ x: 98, y: 2 });
  });

  it("vale null quando la serie non e' disegnabile", () => {
    expect(sparklineEnd([1], 100, 20)).toBeNull();
  });
});
