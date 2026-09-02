import { TestBed } from "@angular/core/testing";
import { type ProgressStage, StageProgressComponent } from "./stage-progress";

describe("StageProgressComponent", () => {
  const stages: readonly ProgressStage[] = [
    { id: "queued", label: "In coda" },
    { id: "processing", label: "Analisi OCR" },
    { id: "completed", label: "Completato" }
  ];

  function renderFixture(inputs: Record<string, unknown>) {
    const fixture = TestBed.createComponent(StageProgressComponent);
    fixture.componentRef.setInput("stages", stages);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture;
  }

  function render(inputs: Record<string, unknown>): HTMLElement {
    return renderFixture(inputs).nativeElement as HTMLElement;
  }

  function statesOf(host: HTMLElement): string[] {
    return Array.from(host.querySelectorAll("li")).map((item) => item.className);
  }

  it("segna come completate le tappe precedenti a quella corrente", () => {
    const host = render({ currentId: "processing" });

    expect(statesOf(host)).toEqual(["done", "current", "pending"]);
  });

  it("marca la tappa corrente per le tecnologie assistive", () => {
    const host = render({ currentId: "processing" });
    const current = host.querySelector("li.current");

    expect(current?.getAttribute("aria-current")).toBe("step");
    expect(current?.textContent).toContain("Analisi OCR");
  });

  it("lascia tutto in attesa finché la pipeline non è partita", () => {
    const host = render({ currentId: null });

    expect(statesOf(host)).toEqual(["pending", "pending", "pending"]);
  });

  it("colora del fallimento solo la tappa in cui si è verificato", () => {
    // Le precedenti erano riuscite davvero: segnarle come fallite direbbe il falso.
    const host = render({ currentId: "processing", failed: true });

    expect(statesOf(host)).toEqual(["done", "failed", "pending"]);
  });

  it("accompagna ogni stato con un testo, non solo con il colore", () => {
    const host = render({ currentId: "processing" });

    expect(host.textContent).toContain("Completato");
    expect(host.textContent).toContain("In corso");
    expect(host.textContent).toContain("In attesa");
  });

  it("segnala la lentezza senza spostare la tappa", () => {
    const host = render({ currentId: "processing", slow: true });

    expect(statesOf(host)).toEqual(["done", "current", "pending"]);
    expect(host.textContent).toContain("più del previsto");
  });

  it("non annuncia la lentezza quando l'elaborazione è già fallita", () => {
    const host = render({ currentId: "processing", slow: true, failed: true });

    expect(host.textContent).not.toContain("più del previsto");
  });

  it("conta il tempo trascorso e lo formatta in minuti quando supera i sessanta secondi", () => {
    // Senza, un'attesa lunga e' indistinguibile da un blocco: la tappa resta
    // la stessa e nulla si muove.
    jest.useFakeTimers().setSystemTime(new Date("2026-08-18T10:00:00Z"));

    try {
      const fixture = renderFixture({ currentId: "processing" });

      expect((fixture.nativeElement as HTMLElement).textContent).toContain("In corso da 0 s");

      jest.advanceTimersByTime(75_000);
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).textContent).toContain("In corso da 1 min 15 s");
    } finally {
      jest.useRealTimers();
    }
  });

  it("ferma il contatore a corsa conclusa, che a quel punto dice quanto e' durata", () => {
    jest.useFakeTimers().setSystemTime(new Date("2026-08-18T10:00:00Z"));

    try {
      const fixture = renderFixture({ currentId: "processing" });
      jest.advanceTimersByTime(30_000);
      fixture.detectChanges();

      fixture.componentRef.setInput("failed", true);
      fixture.detectChanges();
      jest.advanceTimersByTime(60_000);
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).textContent).toContain("Interrotta dopo 30 s");
    } finally {
      jest.useRealTimers();
    }
  });

  it("riparte da zero quando comincia una nuova corsa", () => {
    // Fra una generazione e la successiva la tappa non torna a null: il
    // cronometro proseguiva dalla corsa prima, e la bozza appena chiesta si
    // presentava «in corso da 2 min».
    jest.useFakeTimers().setSystemTime(new Date("2026-08-18T10:00:00Z"));

    try {
      const fixture = renderFixture({ currentId: "processing" });
      jest.advanceTimersByTime(150_000);
      fixture.detectChanges();

      fixture.componentRef.setInput("currentId", "completed");
      fixture.detectChanges();

      // Nuova generazione: si torna alla prima tappa.
      fixture.componentRef.setInput("currentId", "queued");
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).textContent).toContain("In corso da 0 s");
    } finally {
      jest.useRealTimers();
    }
  });

  it("riparte anche quando la corsa nuova comincia dalla tappa a cui si era fermata", () => {
    jest.useFakeTimers().setSystemTime(new Date("2026-08-18T10:00:00Z"));

    try {
      const fixture = renderFixture({ currentId: "completed" });
      jest.advanceTimersByTime(90_000);
      fixture.detectChanges();

      fixture.componentRef.setInput("currentId", "processing");
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).textContent).toContain("In corso da 0 s");
    } finally {
      jest.useRealTimers();
    }
  });

  it("non mostra alcun tempo finche' la pipeline non e' partita", () => {
    expect(render({ currentId: null }).textContent).not.toContain("In corso da");
  });
});
