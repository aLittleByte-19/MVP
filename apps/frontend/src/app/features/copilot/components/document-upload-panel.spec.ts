import { TestBed } from "@angular/core/testing";
import { DocumentUploadPanelComponent } from "./document-upload-panel";

describe("DocumentUploadPanelComponent", () => {
  function render(inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(DocumentUploadPanelComponent);
    fixture.componentRef.setInput("isUploading", false);
    fixture.componentRef.setInput("status", "Pronto");
    fixture.componentRef.setInput("phase", null);
    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }
    fixture.detectChanges();
    return fixture;
  }

  function selectFile(fixture: ReturnType<typeof render>, file: File): void {
    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[type="file"]')!;
    Object.defineProperty(input, "files", { configurable: true, value: [file] });
    input.dispatchEvent(new Event("change"));
  }

  it("propaga il file scelto dal dropzone senza metadati", () => {
    const fixture = render();
    const requests: unknown[] = [];
    fixture.componentInstance.upload.subscribe((request) => requests.push(request));
    const file = new File(["pdf"], "documento.pdf", { type: "application/pdf" });

    selectFile(fixture, file);

    expect(requests).toEqual([{ file, metadata: {} }]);
  });

  it("include i metadati manuali selezionati nell'upload", () => {
    const fixture = render();
    const requests: unknown[] = [];
    fixture.componentInstance.upload.subscribe((request) => requests.push(request));

    const buttons = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>(".typeButton")
    );
    buttons.find((button) => button.textContent?.trim() === "cedolino")?.click();
    fixture.componentInstance["metadataForm"].patchValue({
      month: "3",
      year: "2026",
      companyName: " Acme Srl "
    });
    fixture.detectChanges();

    const file = new File(["pdf"], "cedolini.pdf", { type: "application/pdf" });
    selectFile(fixture, file);

    expect(requests).toEqual([
      {
        file,
        metadata: {
          documentType: "cedolino",
          companyName: "Acme Srl",
          month: 3,
          year: 2026
        }
      }
    ]);
  });

  it("mostra stato e avanzamento quando una fase e' attiva", () => {
    const element = render({ isUploading: true, status: "Analisi in corso", phase: "uploading" })
      .nativeElement as HTMLElement;

    expect(element.textContent).toContain("Analisi in corso");
    expect(element.querySelector<HTMLInputElement>('input[type="file"]')?.disabled).toBe(true);
    expect(element.querySelector("mvp-upload-progress")).not.toBeNull();
  });

  it("non rende l'avanzamento prima dell'upload", () => {
    expect(render().nativeElement.querySelector("mvp-upload-progress")).toBeNull();
  });
});
