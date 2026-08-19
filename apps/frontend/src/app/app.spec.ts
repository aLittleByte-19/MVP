import { signal } from "@angular/core";
import { TestBed } from "@angular/core/testing";
import { NavigationEnd, Router } from "@angular/router";
import { Subject } from "rxjs";
import { MvpStateStore } from "./core/state/mvp-state.store";
import { ThemeService } from "./core/theme/theme.service";
import { AppComponent } from "./app";

describe("AppComponent", () => {
  const events = new Subject<NavigationEnd>();
  let navigate: jest.Mock;
  let loadOnce: jest.Mock;

  beforeEach(() => {
    navigate = jest.fn(() => Promise.resolve(true));
    loadOnce = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        { provide: Router, useValue: { url: "/assistant?draft=7", events, navigate } },
        { provide: MvpStateStore, useValue: { loadOnce } },
        { provide: ThemeService, useValue: { theme: signal("light"), toggle: jest.fn() } }
      ]
    });
    TestBed.overrideComponent(AppComponent, { set: { template: "", imports: [] } });
  });

  it("carica lo store e sincronizza vista, titolo e prima sezione dall'URL", () => {
    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();

    expect(loadOnce).toHaveBeenCalledTimes(1);
    expect(fixture.componentInstance["activeView"]()).toBe("assistant");
    expect(fixture.componentInstance["title"]()).toBe("AI Assistant Generativo");
    expect(fixture.componentInstance["activeChildId"]()).toBe("assistant-compose");
  });

  it("segue le navigazioni concluse e ripiega su overview per URL ignoti", () => {
    const fixture = TestBed.createComponent(AppComponent);

    events.next(new NavigationEnd(1, "/assistant", "/copilot#copilot-metrics"));
    expect(fixture.componentInstance["activeView"]()).toBe("copilot");

    events.next(new NavigationEnd(2, "/copilot", "/settings"));
    expect(fixture.componentInstance["activeView"]()).toBe("overview");
  });

  it("naviga e scorre alla destinazione dopo il routing", async () => {
    const fixture = TestBed.createComponent(AppComponent);
    const target = document.createElement("div");
    target.id = "copilot-upload";
    target.scrollIntoView = jest.fn();
    document.body.appendChild(target);
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });

    fixture.componentInstance["navigate"]("copilot", "copilot-upload");
    await Promise.resolve();

    expect(navigate).toHaveBeenCalledWith(["copilot"]);
    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
    animation.mockRestore();
    target.remove();
  });

  it("porta in cima e aggiorna la visibilita in base allo scroll", () => {
    const fixture = TestBed.createComponent(AppComponent);
    const topbar = document.createElement("div");
    topbar.id = "mvp-topbar";
    topbar.scrollIntoView = jest.fn();
    document.body.appendChild(topbar);
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });
    Object.defineProperty(window, "scrollY", { configurable: true, value: 500 });

    fixture.componentInstance["updateBackToTopVisibility"]();
    fixture.componentInstance["scrollToTop"]();

    expect(fixture.componentInstance["showBackToTop"]()).toBe(true);
    expect(topbar.scrollIntoView).toHaveBeenCalled();
    animation.mockRestore();
    topbar.remove();
  });

  it("evidenzia la sezione raggiunta e l'ultima quando lo scroll e' esaurito", () => {
    // Il caso che l'IntersectionObserver non copriva: le sezioni finali non
    // risalgono mai alla banda di attivazione, perche' il documento finisce
    // prima, e restavano senza evidenziazione.
    const fixture = TestBed.createComponent(AppComponent);
    const tops: Record<string, number> = {
      "assistant-compose": -900,
      "assistant-review": -400,
      "assistant-history": 60,
      "assistant-metrics": 700
    };
    const elements = Object.entries(tops).map(([id, top]) => {
      const element = document.createElement("div");
      element.id = id;
      element.getBoundingClientRect = (() => ({ top })) as never;
      document.body.appendChild(element);
      return element;
    });
    Object.defineProperty(window, "innerHeight", { configurable: true, value: 800 });
    Object.defineProperty(document.documentElement, "scrollHeight", { configurable: true, value: 3000 });
    Object.defineProperty(window, "scrollY", { configurable: true, value: 1000 });

    fixture.componentInstance["updateActiveChild"]();
    expect(fixture.componentInstance["activeChildId"]()).toBe("assistant-history");

    Object.defineProperty(window, "scrollY", { configurable: true, value: 2200 });
    fixture.componentInstance["updateActiveChild"]();

    expect(fixture.componentInstance["activeChildId"]()).toBe("assistant-metrics");

    for (const element of elements) {
      element.remove();
    }
  });

  it("da' un indirizzo reale a ogni voce di navigazione", () => {
    // Le voci sono collegamenti: senza href non sarebbero apribili in una
    // nuova scheda ne' annunciate come tali.
    const fixture = TestBed.createComponent(AppComponent);

    expect(fixture.componentInstance["linkTo"]("copilot")).toBe("/copilot");
    expect(fixture.componentInstance["linkTo"]("copilot", "copilot-metrics")).toBe(
      "/copilot#copilot-metrics"
    );
  });

  it("gestisce il click semplice nella SPA e lascia al browser quello con modificatori", () => {
    const fixture = TestBed.createComponent(AppComponent);
    const plain = { button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, preventDefault: jest.fn() };

    fixture.componentInstance["onNavigate"](plain as unknown as MouseEvent, "copilot");

    expect(plain.preventDefault).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith(["copilot"]);

    navigate.mockClear();

    for (const modifier of ["ctrlKey", "metaKey", "shiftKey", "altKey"]) {
      const modified = { ...plain, [modifier]: true, preventDefault: jest.fn() };
      fixture.componentInstance["onNavigate"](modified as unknown as MouseEvent, "copilot");
      expect(modified.preventDefault).not.toHaveBeenCalled();
    }

    // Tasto centrale: apre in una nuova scheda, non deve essere intercettato.
    const middle = { ...plain, button: 1, preventDefault: jest.fn() };
    fixture.componentInstance["onNavigate"](middle as unknown as MouseEvent, "copilot");

    expect(middle.preventDefault).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });
});
