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
    Object.defineProperty(window, "IntersectionObserver", { configurable: true, value: undefined });
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
});
