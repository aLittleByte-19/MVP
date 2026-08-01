import { TestBed } from "@angular/core/testing";
import { FileDropzoneComponent } from "./file-dropzone";

describe("FileDropzoneComponent", () => {
  function render(inputs: Record<string, unknown> = { label: "Trascina qui il PDF" }) {
    const fixture = TestBed.createComponent(FileDropzoneComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture;
  }

  /** Simula la scelta di un file, che il browser non permette di fare a mano. */
  function chooseFile(input: HTMLInputElement, ...files: File[]): void {
    Object.defineProperty(input, "files", { configurable: true, value: files });
    input.dispatchEvent(new Event("change"));
  }

  it("accetta solo PDF, coerentemente con la pipeline documentale", () => {
    const input = (render().nativeElement as HTMLElement).querySelector("input") as HTMLInputElement;

    expect(input.accept).toBe("application/pdf");
  });

  it("mostra l'etichetta richiesta", () => {
    const element = render({ label: "Carica i cedolini" }).nativeElement as HTMLElement;

    expect(element.textContent).toContain("Carica i cedolini");
  });

  it("consegna al padre il file scelto", () => {
    const fixture = render();
    const chosen: File[] = [];
    fixture.componentInstance.fileSelected.subscribe((file) => chosen.push(file));
    const input = (fixture.nativeElement as HTMLElement).querySelector("input") as HTMLInputElement;
    const file = new File(["contenuto"], "cedolini.pdf", { type: "application/pdf" });

    chooseFile(input, file);

    expect(chosen).toEqual([file]);
  });

  it("svuota il campo dopo la scelta, cosi' lo stesso file si puo' ricaricare", () => {
    // Senza il reset, riselezionare lo stesso file non emette nessun evento
    // change e l'utente si trova il pulsante che non fa niente.
    const fixture = render();
    const input = (fixture.nativeElement as HTMLElement).querySelector("input") as HTMLInputElement;

    chooseFile(input, new File(["contenuto"], "cedolini.pdf", { type: "application/pdf" }));

    expect(input.value).toBe("");
  });

  it("non emette nulla se la scelta viene annullata", () => {
    const fixture = render();
    const chosen: File[] = [];
    fixture.componentInstance.fileSelected.subscribe((file) => chosen.push(file));
    const input = (fixture.nativeElement as HTMLElement).querySelector("input") as HTMLInputElement;

    chooseFile(input);

    expect(chosen).toEqual([]);
  });

  it("si disabilita durante un caricamento gia' in corso", () => {
    const element = render({ label: "Carica", disabled: true }).nativeElement as HTMLElement;

    expect((element.querySelector("input") as HTMLInputElement).disabled).toBe(true);
  });

  it("e' abilitato di default", () => {
    const element = render().nativeElement as HTMLElement;

    expect((element.querySelector("input") as HTMLInputElement).disabled).toBe(false);
  });
});
