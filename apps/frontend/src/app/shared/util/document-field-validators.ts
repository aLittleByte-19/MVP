import type { AbstractControl, ValidationErrors } from "@angular/forms";

/** Tipologie ammesse per "documentType" (UC-43) — allineate all'enum OpenAPI. */
export const DOCUMENT_TYPE_OPTIONS = [
  "cedolino",
  "CU",
  "comunicazione",
  "documento da firmare",
  "lettera",
  "altro"
] as const;

const CODICE_FISCALE_ODD_VALUES: Record<string, number> = {
  "0": 1, "1": 0, "2": 5, "3": 7, "4": 9,
  "5": 13, "6": 15, "7": 17, "8": 19, "9": 21,
  A: 1, B: 0, C: 5, D: 7, E: 9,
  F: 13, G: 15, H: 17, I: 19, J: 21,
  K: 2, L: 4, M: 18, N: 20, O: 11,
  P: 3, Q: 6, R: 8, S: 12, T: 14,
  U: 16, V: 10, W: 22, X: 25, Y: 24, Z: 23
};

const CODICE_FISCALE_EVEN_VALUES: Record<string, number> = {
  "0": 0, "1": 1, "2": 2, "3": 3, "4": 4,
  "5": 5, "6": 6, "7": 7, "8": 8, "9": 9,
  A: 0, B: 1, C: 2, D: 3, E: 4,
  F: 5, G: 6, H: 7, I: 8, J: 9,
  K: 10, L: 11, M: 12, N: 13, O: 14,
  P: 15, Q: 16, R: 17, S: 18, T: 19,
  U: 20, V: 21, W: 22, X: 23, Y: 24, Z: 25
};

/** Controllo di validità formale (checksum) del codice fiscale italiano — UC-46/UC-69. */
export function isValidCodiceFiscale(value: string): boolean {
  const code = value.toUpperCase();

  if (!/^[A-Z0-9]{16}$/.test(code)) {
    return false;
  }

  let sum = 0;

  for (let position = 0; position < 15; position++) {
    const char = code[position];
    sum += position % 2 === 0 ? CODICE_FISCALE_ODD_VALUES[char] : CODICE_FISCALE_EVEN_VALUES[char];
  }

  const expectedCheckChar = String.fromCharCode(65 + (sum % 26));

  return code[15] === expectedCheckChar;
}

/** Validator reactive-forms: ignora i campi vuoti (il campo è nullable). */
export function codiceFiscaleValidator(control: AbstractControl): ValidationErrors | null {
  const value = ((control.value as string | null) ?? "").trim();

  if (value === "") {
    return null;
  }

  return isValidCodiceFiscale(value) ? null : { codiceFiscale: true };
}
