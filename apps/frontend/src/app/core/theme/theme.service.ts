import { Injectable, effect, signal } from "@angular/core";

export type Theme = "light" | "dark";

const STORAGE_KEY = "mvp-theme";

/**
 * Tema chiaro/scuro: preferenza persistita in `localStorage`, fallback su
 * `prefers-color-scheme` e attributo `data-mvp-theme` applicato a `<html>`
 * (su cui agiscono i token CSS).
 */
@Injectable({ providedIn: "root" })
export class ThemeService {
  private readonly _theme = signal<Theme>(getInitialTheme());
  readonly theme = this._theme.asReadonly();

  constructor() {
    effect(() => {
      const theme = this._theme();
      document.documentElement.dataset["mvpTheme"] = theme;

      try {
        window.localStorage.setItem(STORAGE_KEY, theme);
      } catch {
        // localStorage non disponibile (es. modalita' privata): si ignora.
      }
    });
  }

  toggle(): void {
    this._theme.update((current) => (current === "dark" ? "light" : "dark"));
  }
}

function getInitialTheme(): Theme {
  let stored: string | null = null;

  try {
    stored = window.localStorage.getItem(STORAGE_KEY);
  } catch {
    stored = null;
  }

  if (stored === "dark" || stored === "light") {
    return stored;
  }

  if (typeof window.matchMedia !== "function") {
    return "light";
  }

  return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}
