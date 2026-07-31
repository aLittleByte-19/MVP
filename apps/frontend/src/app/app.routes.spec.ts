import type { Route } from "@angular/router";
import { routes } from "./app.routes";

/**
 * Le rotte sono contratto verso l'esterno: un indirizzo salvato nei preferiti o
 * incollato in una mail deve continuare a funzionare, e la SPA servita dal CDN
 * ha un fallback sui deep link che presuppone questi percorsi.
 */
function routeFor(path: string): Route {
  const route = routes.find((candidate) => candidate.path === path);

  expect(route).toBeDefined();

  return route as Route;
}

describe("rotte applicative", () => {
  it("porta la radice sulla panoramica", () => {
    const root = routeFor("");

    expect(root.pathMatch).toBe("full");
    expect(root.redirectTo).toBe("overview");
  });

  it.each(["overview", "assistant", "copilot"])("espone la vista %s come rotta di primo livello", (path) => {
    expect(routeFor(path).loadComponent).toBeDefined();
  });

  it.each([
    ["overview", "NEXUM - Overview"],
    ["assistant", "NEXUM - AI Assistant"],
    ["copilot", "NEXUM - Copilot CdL"]
  ])("da alla vista %s un titolo di pagina proprio", (path, title) => {
    expect(routeFor(path).title).toBe(title);
  });

  it.each(["overview", "assistant", "copilot"])(
    "associa alla rotta %s la vista usata dalla navigazione",
    (path) => {
      expect(routeFor(path).data?.["view"]).toBe(path);
    }
  );

  it("riporta sulla panoramica qualunque indirizzo sconosciuto", () => {
    // Il CDN serve index.html su ogni deep link: senza questa rotta un
    // indirizzo vecchio mostrerebbe una pagina vuota invece di reindirizzare.
    expect(routes.at(-1)).toMatchObject({ path: "**", redirectTo: "overview" });
  });

  it.each(["overview", "assistant", "copilot"])("carica la vista %s solo quando serve", async (path) => {
    // Il lazy loading tiene fuori dal bundle iniziale le viste non visitate.
    const loaded = await routeFor(path).loadComponent?.();

    expect(loaded).toBeDefined();
  });
});
