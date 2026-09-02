import { TestBed } from "@angular/core/testing";
import { ThemeService } from "./theme.service";

const STORAGE_KEY = "mvp-theme";

/** Sostituisce `window.matchMedia`, che jsdom non implementa. */
function stubMatchMedia(prefersDark: boolean): void {
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    writable: true,
    value: (query: string) => ({ matches: prefersDark, media: query })
  });
}

describe("ThemeService", () => {
  beforeEach(() => {
    window.localStorage.clear();
    delete (window as { matchMedia?: unknown }).matchMedia;
    document.documentElement.removeAttribute("data-mvp-theme");
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  describe("tema iniziale", () => {
    it("usa la preferenza salvata quando c'e'", () => {
      window.localStorage.setItem(STORAGE_KEY, "dark");

      expect(TestBed.inject(ThemeService).theme()).toBe("dark");
    });

    it("ignora un valore salvato che non e' un tema valido", () => {
      window.localStorage.setItem(STORAGE_KEY, "arcobaleno");

      expect(TestBed.inject(ThemeService).theme()).toBe("light");
    });

    it("segue prefers-color-scheme quando non c'e' preferenza salvata", () => {
      stubMatchMedia(true);

      expect(TestBed.inject(ThemeService).theme()).toBe("dark");
    });

    it("resta sul chiaro se il sistema non preferisce lo scuro", () => {
      stubMatchMedia(false);

      expect(TestBed.inject(ThemeService).theme()).toBe("light");
    });

    it("ripiega sul chiaro quando matchMedia non esiste", () => {
      // Ambienti senza matchMedia non devono far esplodere l'avvio.
      expect(TestBed.inject(ThemeService).theme()).toBe("light");
    });

    it("ripiega sul chiaro quando localStorage e' inaccessibile", () => {
      // Es. navigazione privata: leggere lo storage solleva.
      jest.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
        throw new Error("storage non disponibile");
      });

      expect(TestBed.inject(ThemeService).theme()).toBe("light");
    });
  });

  describe("toggle", () => {
    it("passa da chiaro a scuro e ritorno", () => {
      const service = TestBed.inject(ThemeService);

      expect(service.theme()).toBe("light");
      service.toggle();
      expect(service.theme()).toBe("dark");
      service.toggle();
      expect(service.theme()).toBe("light");
    });
  });

  describe("propagazione del tema", () => {
    it("scrive l'attributo su <html>, su cui agiscono i token CSS", () => {
      const service = TestBed.inject(ThemeService);
      TestBed.tick();

      expect(document.documentElement.dataset["mvpTheme"]).toBe("light");

      service.toggle();
      TestBed.tick();

      expect(document.documentElement.dataset["mvpTheme"]).toBe("dark");
    });

    it("persiste la scelta per la sessione successiva", () => {
      const service = TestBed.inject(ThemeService);
      service.toggle();
      TestBed.tick();

      expect(window.localStorage.getItem(STORAGE_KEY)).toBe("dark");
    });

    it("applica comunque il tema se la scrittura su localStorage fallisce", () => {
      jest.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
        throw new Error("quota superata");
      });

      const service = TestBed.inject(ThemeService);
      service.toggle();

      expect(() => TestBed.tick()).not.toThrow();
      expect(document.documentElement.dataset["mvpTheme"]).toBe("dark");
    });
  });
});
