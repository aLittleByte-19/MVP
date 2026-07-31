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

  it("propaga il file scelto dal dropzone", () => {
    const fixture = render();
    const files: File[] = [];
    fixture.componentInstance.upload.subscribe((file) => files.push(file));
    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[type="file"]')!;
    const file = new File(["pdf"], "documento.pdf", { type: "application/pdf" });
    Object.defineProperty(input, "files", { configurable: true, value: [file] });

    input.dispatchEvent(new Event("change"));

    expect(files).toEqual([file]);
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
