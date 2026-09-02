import { FormControl } from "@angular/forms";
import {
  DOCUMENT_TYPE_OPTIONS,
  codiceFiscaleValidator,
  isValidCodiceFiscale
} from "./document-field-validators";

/** Carattere di controllo calcolato a parte con le tabelle ufficiali, non con la funzione sotto test. */
const VALID_CODES = ["RSSMRA85M01H501Q", "BNCLRA90A41F205I", "VRDGPP70T10L219Z", "MRTNNA92E45G273A"];

describe("isValidCodiceFiscale", () => {
  it.each(VALID_CODES)("accetta il codice valido %s", (code) => {
    expect(isValidCodiceFiscale(code)).toBe(true);
  });

  it("accetta un codice valido scritto in minuscolo", () => {
    expect(isValidCodiceFiscale("rssmra85m01h501q")).toBe(true);
  });

  it("rifiuta un codice con carattere di controllo sbagliato", () => {
    // Stesse prime 15 posizioni del primo codice valido: cambia solo il check.
    expect(isValidCodiceFiscale("RSSMRA85M01H501Z")).toBe(false);
  });

  it.each([
    ["troppo corto", "RSSMRA85M01H50"],
    ["troppo lungo", "RSSMRA85M01H501QQ"],
    ["vuoto", ""],
    ["con un trattino", "RSSMRA85M01H50-Q"],
    ["con uno spazio", "RSSMRA85M01H50 Q"]
  ])("rifiuta un codice %s", (_caso, code) => {
    expect(isValidCodiceFiscale(code)).toBe(false);
  });

  it("distingue le posizioni pari da quelle dispari", () => {
    // Scambiando due caratteri adiacenti cambia la somma, perche' lo stesso
    // carattere pesa diversamente a seconda della posizione: e' la proprieta'
    // che rende il checksum capace di intercettare le trasposizioni.
    expect(isValidCodiceFiscale("RSSMRA85M01H510Q")).toBe(false);
  });
});

describe("codiceFiscaleValidator", () => {
  it("considera valido un campo vuoto, perche' il codice e' facoltativo", () => {
    expect(codiceFiscaleValidator(new FormControl(""))).toBeNull();
  });

  it("considera valido un campo di soli spazi", () => {
    expect(codiceFiscaleValidator(new FormControl("   "))).toBeNull();
  });

  it("considera valido un campo mai compilato", () => {
    expect(codiceFiscaleValidator(new FormControl(null))).toBeNull();
  });

  it("non segnala errori su un codice valido", () => {
    expect(codiceFiscaleValidator(new FormControl(VALID_CODES[0]))).toBeNull();
  });

  it("segnala l'errore su un codice non valido", () => {
    expect(codiceFiscaleValidator(new FormControl("NONVALIDO1234567"))).toEqual({ codiceFiscale: true });
  });

  it("ignora gli spazi attorno al codice", () => {
    expect(codiceFiscaleValidator(new FormControl(`  ${VALID_CODES[0]}  `))).toBeNull();
  });
});

describe("DOCUMENT_TYPE_OPTIONS", () => {
  it("elenca le tipologie ammesse senza duplicati", () => {
    expect(DOCUMENT_TYPE_OPTIONS).toEqual([
      "cedolino",
      "CU",
      "comunicazione",
      "documento da firmare",
      "lettera",
      "altro"
    ]);
    expect(new Set(DOCUMENT_TYPE_OPTIONS).size).toBe(DOCUMENT_TYPE_OPTIONS.length);
  });
});
