import { formatDateForDisplay, formatFallback, getSubDocumentNumericId } from "./formatters";

describe("formatters", () => {
  it("returns fallback text for empty API values", () => {
    expect(formatFallback(null)).toBe("Non disponibile");
    expect(formatFallback(undefined, "Fallback")).toBe("Fallback");
    expect(formatFallback("", "Fallback")).toBe("Fallback");
  });

  it("formats present values without changing their display contract", () => {
    expect(formatFallback("Documento")).toBe("Documento");
    expect(formatFallback(42)).toBe("42");
  });

  it("extracts numeric sub-document ids from generated view ids", () => {
    expect(getSubDocumentNumericId("sub-123")).toBe(123);
  });

  it("formats ISO dates as day/month/year for display", () => {
    expect(formatDateForDisplay("2023-01-22")).toBe("22/01/2023");
    expect(formatDateForDisplay("22/01/2023")).toBe("22/01/2023");
    expect(formatDateForDisplay(null)).toBe("Non disponibile");
  });
});
