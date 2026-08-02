import { TestBed } from "@angular/core/testing";
import type { GeneratedDraft, RateDraftPayload } from "../assistant.model";
import { RATING_COMMENT_MAX_LENGTH } from "../assistant.model";
import { GeneratedCommunicationPreviewComponent } from "./generated-communication-preview";

function draft(overrides: Partial<GeneratedDraft> = {}): GeneratedDraft {
  return {
    id: 7,
    title: "Nuova area documentale",
    body: "La nuova area documentale e disponibile.",
    status: "Bozza",
    statusValue: "draft",
    coverStatus: "removed",
    generationStatus: "completed",
    previewUrl: "/communications/7/preview",
    exportUrl: "/communications/7/export",
    rating: null,
    ...overrides
  };
}

describe("GeneratedCommunicationPreviewComponent", () => {
  function render(current: GeneratedDraft | null = null, inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(GeneratedCommunicationPreviewComponent);
    fixture.componentRef.setInput("draft", current);
    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }
    fixture.detectChanges();
    return fixture;
  }

  it("mostra lo stato vuoto e conta zero caratteri senza bozza", () => {
    const fixture = render();

    expect(fixture.componentInstance["bodyLength"]()).toBe(0);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain("La bozza generata apparira qui.");
  });

  it("riconosce copertina, attesa, scarto e disponibilita anteprima", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;
    const ready = draft({ coverImageUrl: " /cover.png ", coverStatus: "ready" });

    expect(component["hasCover"](ready)).toBe(true);
    expect(component["hasCover"](draft({ coverImageUrl: " " }))).toBe(false);
    expect(component["isCoverPending"](draft({ coverStatus: "pending" }))).toBe(true);
    expect(component["isCoverPending"](draft({ coverStatus: "processing" }))).toBe(true);
    expect(component["isCoverPending"](draft({ coverStatus: "failed" }))).toBe(false);
    expect(component["isDiscarded"](draft({ status: "Scartata" }))).toBe(true);
    expect(component["isApproved"](draft({ status: "Approvata" }))).toBe(true);
    expect(component["isApproved"](draft({ status: "Bozza" }))).toBe(false);
    expect(component["isReadyForPreview"](draft())).toBe(true);
    expect(component["isReadyForPreview"](draft({ generationStatus: "processing" }))).toBe(false);
    expect(component["isReadyForPreview"](draft({ status: "Scartata" }))).toBe(false);
    expect(component["isReadyForPreview"](draft({ previewUrl: "" }))).toBe(false);
  });

  it.each([
    [draft({ coverImageUrl: "/cover.png", coverStatus: "ready" }), "img.preview-image"],
    [draft({ coverStatus: "processing" }), "mvp-empty-state"],
    [draft({ coverStatus: "failed", coverError: "Cover non disponibile" }), ".warning"]
  ])("rende lo stato della copertina", (current, selector) => {
    const element = render(current).nativeElement as HTMLElement;

    expect(element.querySelector(selector)).not.toBeNull();
  });

  it("sincronizza form, rating e commento quando cambia bozza", () => {
    const fixture = render(draft({ rating: 4, ratingComment: "Ben riuscita" }));
    const component = fixture.componentInstance;

    expect(component["form"].getRawValue()).toEqual({
      title: "Nuova area documentale",
      body: "La nuova area documentale e disponibile."
    });
    expect(component["hasRated"]()).toBe(true);
    expect(component["displayRating"]()).toBe(4);
    expect(component["commentControl"].disabled).toBe(true);
    expect(component["commentLength"]()).toBe("Ben riuscita".length);

    component["isConfirmingDiscard"].set(true);
    fixture.componentRef.setInput("draft", draft({ id: 8, title: "Seconda", body: "Nuovo testo" }));
    fixture.detectChanges();

    expect(component["form"].getRawValue()).toEqual({ title: "Seconda", body: "Nuovo testo" });
    expect(component["selectedRating"]()).toBe(0);
    expect(component["commentControl"].enabled).toBe(true);
    expect(component["isConfirmingDiscard"]()).toBe(false);
  });

  it("non risincronizza una bozza invariata finche' non cambia il rating", () => {
    const current = draft();
    const fixture = render(current);
    const component = fixture.componentInstance;
    component["commentControl"].setValue("Testo locale");
    component["selectedRating"].set(3);

    fixture.componentRef.setInput("draft", { ...current, body: "Testo dallo stream" });
    fixture.detectChanges();
    expect(component["commentControl"].value).toBe("Testo locale");
    expect(component["selectedRating"]()).toBe(3);

    fixture.componentRef.setInput("draft", { ...current, rating: 5, ratingComment: "Salvato" });
    fixture.detectChanges();
    expect(component["selectedRating"]()).toBe(5);
    expect(component["commentControl"].value).toBe("Salvato");
  });

  it("emette il file scelto e resetta il campo", () => {
    const fixture = render(draft());
    const files: File[] = [];
    fixture.componentInstance.uploadCover.subscribe((file) => files.push(file));
    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[type="file"]')!;
    const file = new File(["cover"], "cover.png", { type: "image/png" });
    Object.defineProperty(input, "files", { configurable: true, value: { item: () => file } });

    input.dispatchEvent(new Event("change"));

    expect(files).toEqual([file]);
    expect(input.value).toBe("");
  });

  it("ignora la selezione file annullata", () => {
    const fixture = render(draft());
    const files: File[] = [];
    fixture.componentInstance.uploadCover.subscribe((file) => files.push(file));
    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[type="file"]')!;
    Object.defineProperty(input, "files", { configurable: true, value: { item: () => null } });

    input.dispatchEvent(new Event("change"));

    expect(files).toEqual([]);
  });

  it("seleziona le stelle solo se la valutazione e' modificabile", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;

    component["onStarsSelected"](4);
    expect(component["selectedRating"]()).toBe(4);

    fixture.componentRef.setInput("isRating", true);
    fixture.detectChanges();
    component["onStarsSelected"](2);
    expect(component["selectedRating"]()).toBe(4);

    fixture.componentRef.setInput("isRating", false);
    fixture.componentRef.setInput("draft", draft({ rating: 5 }));
    fixture.detectChanges();
    component["onStarsSelected"](1);
    expect(component["selectedRating"]()).toBe(5);
  });

  it("richiede un punteggio valido prima di emettere la valutazione", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;
    const ratings: RateDraftPayload[] = [];
    component.rate.subscribe((value) => ratings.push(value));

    component["submitRating"]();
    expect(component["localError"]()).toContain("Seleziona un punteggio");

    component["selectedRating"].set(6);
    component["submitRating"]();
    expect(ratings).toEqual([]);
  });

  it("rifiuta un commento troppo lungo e ripulisce l'errore quando viene accorciato", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;
    const ratings: RateDraftPayload[] = [];
    component.rate.subscribe((value) => ratings.push(value));
    component["selectedRating"].set(5);
    component["commentControl"].setValue("x".repeat(RATING_COMMENT_MAX_LENGTH + 1));

    expect(component["commentTooLong"]()).toBe(true);
    component["submitRating"]();
    expect(component["localError"]()).toContain("lunghezza massima");
    expect(ratings).toEqual([]);

    component["commentControl"].setValue("Corretto");
    expect(component["localError"]()).toBeNull();
    expect(component["commentTooLong"]()).toBe(false);
  });

  it("emette rating con commento normalizzato o senza commento", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;
    const ratings: RateDraftPayload[] = [];
    component.rate.subscribe((value) => ratings.push(value));
    component["selectedRating"].set(5);

    component["commentControl"].setValue("  Ottima bozza  ");
    component["submitRating"]();
    component["commentControl"].setValue("   ");
    component["submitRating"]();

    expect(ratings).toEqual([{ rating: 5, comment: "Ottima bozza" }, { rating: 5 }]);
  });

  it("non invia rating mentre e' in corso o dopo una valutazione", () => {
    const busy = render(draft(), { isRating: true });
    const busyRatings: RateDraftPayload[] = [];
    busy.componentInstance.rate.subscribe((value) => busyRatings.push(value));
    busy.componentInstance["selectedRating"].set(4);
    busy.componentInstance["submitRating"]();
    expect(busyRatings).toEqual([]);

    const rated = render(draft({ rating: 3 }));
    const ratedValues: RateDraftPayload[] = [];
    rated.componentInstance.rate.subscribe((value) => ratedValues.push(value));
    rated.componentInstance["submitRating"]();
    expect(ratedValues).toEqual([]);
  });

  it("avvia, annulla e salva una modifica valida", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;
    const saves: { communicationId: number; payload: { title: string; body: string } }[] = [];
    component.saveRequested.subscribe((value) => saves.push(value));

    component["startEdit"](draft());
    expect(component["isEditing"]()).toBe(true);
    component["form"].setValue({ title: "Titolo modificato", body: "Corpo modificato" });
    component["save"](7);
    expect(saves).toEqual([{ communicationId: 7, payload: { title: "Titolo modificato", body: "Corpo modificato" } }]);

    component["cancelEdit"](draft());
    expect(component["isEditing"]()).toBe(false);
    expect(component["form"].getRawValue().title).toBe("Nuova area documentale");
  });

  it("non salva un form invalido e marca i campi", () => {
    const fixture = render(draft());
    const component = fixture.componentInstance;
    const saves: unknown[] = [];
    component.saveRequested.subscribe((value) => saves.push(value));
    component["form"].setValue({ title: "", body: "" });

    component["save"](7);

    expect(saves).toEqual([]);
    expect(component["form"].controls.title.touched).toBe(true);
    expect(component["form"].controls.body.touched).toBe(true);
  });

  it("propaga le azioni di copertina, rigenerazione, salvataggio e scarto", () => {
    const fixture = render(draft({ coverImageUrl: "/cover.png", coverStatus: "ready" }));
    const events: string[] = [];
    fixture.componentInstance.removeCover.subscribe(() => events.push("remove"));
    fixture.componentInstance.regenerate.subscribe(() => events.push("regenerate"));
    fixture.componentInstance.discard.subscribe(() => events.push("discard"));
    fixture.componentInstance.saveToHistory.subscribe(() => events.push("saveToHistory"));

    fixture.componentInstance.removeCover.emit();
    fixture.componentInstance.regenerate.emit();
    fixture.componentInstance.discard.emit();
    fixture.componentInstance.saveToHistory.emit();

    expect(events).toEqual(["remove", "regenerate", "discard", "saveToHistory"]);
  });

  it("nasconde solo il salvataggio una volta che la bozza e' nello storico, restando modificabile e rigenerabile", () => {
    const fixture = render(draft({ status: "Approvata", statusValue: "approved" }));
    const element = fixture.nativeElement as HTMLElement;
    const labels = Array.from(element.querySelectorAll("button")).map((button) => button.textContent?.trim());

    expect(labels).not.toContain("Salva nello storico");
    expect(labels).toContain("Rigenera bozza");
    expect(labels).toContain("Modifica");
  });

  it("nasconde modifica, rigenerazione e azioni copertina per una bozza scartata", () => {
    const fixture = render(draft({ status: "Scartata", statusValue: "draft" }));
    const element = fixture.nativeElement as HTMLElement;
    const labels = Array.from(element.querySelectorAll("button")).map((button) => button.textContent?.trim());

    expect(labels).not.toContain("Salva nello storico");
    expect(labels).not.toContain("Rigenera bozza");
    expect(labels).not.toContain("Modifica");
    expect(labels).not.toContain("Cambia immagine");
    expect(labels).not.toContain("Rimuovi immagine");
  });
});
