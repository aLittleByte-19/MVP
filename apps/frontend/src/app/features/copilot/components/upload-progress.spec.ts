import { TestBed } from "@angular/core/testing";
import { UploadProgressComponent } from "./upload-progress";
import type { DocumentUploadPhase } from "../data/document-workflow.service";

/**
 * L'adattatore fra le fasi della pipeline documentale e la barra condivisa.
 * Le fasi arrivano dagli eventi reali dello stream SSE, quindi la mappatura
 * non e' cosmetica: se sbaglia, la barra racconta un avanzamento che non c'e'.
 */
describe("UploadProgressComponent", () => {
  function render(phase: DocumentUploadPhase | null): HTMLElement {
    const fixture = TestBed.createComponent(UploadProgressComponent);
    fixture.componentRef.setInput("phase", phase);
    fixture.detectChanges();

    return fixture.nativeElement.querySelector("[role=progressbar]") as HTMLElement;
  }

  it.each([
    ["uploading", "20"],
    ["queued", "40"],
    ["processing", "60"],
    ["extracting", "85"],
    ["completed", "100"],
    ["failed", "100"]
  ] as [DocumentUploadPhase, string][])("porta la fase %s al %s per cento", (phase, expected) => {
    expect(render(phase).getAttribute("aria-valuenow")).toBe(expected);
  });

  it("resta a zero finche' non c'e' una fase", () => {
    expect(render(null).getAttribute("aria-valuenow")).toBe("0");
  });

  it.each([
    ["uploading", "isActive"],
    ["queued", "isActive"],
    ["processing", "isActive"],
    ["extracting", "isActive"],
    ["completed", "isDone"],
    ["failed", "isError"]
  ] as [DocumentUploadPhase, string][])("nello stato %s la barra e' %s", (phase, expectedClass) => {
    expect(render(phase).classList.contains(expectedClass)).toBe(true);
  });

  it("una elaborazione fallita si vede come errore, non come completata", () => {
    // Entrambe stanno al 100%: e' il colore a distinguerle, quindi va verificato.
    const failed = render("failed");

    expect(failed.classList.contains("isError")).toBe(true);
    expect(failed.classList.contains("isDone")).toBe(false);
  });

  it("annuncia di quale elaborazione si tratta", () => {
    expect(render("queued").getAttribute("aria-label")).toBe("Avanzamento elaborazione documento");
  });
});
