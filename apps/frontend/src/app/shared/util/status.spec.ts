import { getReviewStatusShortLabel, getReviewStatusTone } from "./status";

describe("status utilities", () => {
  it("maps review states to visual tones used by badges", () => {
    expect(getReviewStatusTone("quarantined")).toBe("danger");
    expect(getReviewStatusTone("needs_review")).toBe("warning");
    expect(getReviewStatusTone("auto_validated")).toBe("info");
    expect(getReviewStatusTone("manually_validated")).toBe("success");
    expect(getReviewStatusTone("unknown")).toBe("neutral");
  });

  it("abbrevia lo stato di revisione per le colonne strette", () => {
    // Sotto l'intestazione "Validazione" la sola qualificazione basta: la
    // frase intera del backend resta dov'e' leggibile per esteso.
    expect(getReviewStatusShortLabel("auto_validated", "Validato automaticamente")).toBe("Automatica");
    expect(getReviewStatusShortLabel("manually_validated", "Validato manualmente")).toBe("Manuale");
    expect(getReviewStatusShortLabel("needs_review", "Da revisionare")).toBe("Da verificare");
    expect(getReviewStatusShortLabel("quarantined", "In quarantena")).toBe("Quarantena");
  });

  it("davanti a uno stato ignoto tiene l'etichetta che arriva dal backend", () => {
    expect(getReviewStatusShortLabel(undefined, "Sconosciuto")).toBe("Sconosciuto");
  });
});
