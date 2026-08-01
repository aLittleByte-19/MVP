import { TestBed } from "@angular/core/testing";
import type { CommunicationDraftForm } from "../assistant.model";
import { CommunicationGeneratorPanelComponent } from "./communication-generator-panel";

describe("CommunicationGeneratorPanelComponent", () => {
  function render(inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(CommunicationGeneratorPanelComponent);
    fixture.componentRef.setInput("isGenerating", false);
    fixture.componentRef.setInput("status", "Pronto");
    fixture.componentRef.setInput("phase", null);
    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }
    fixture.detectChanges();
    return fixture;
  }

  it("emette il valore completo del form valido", () => {
    const fixture = render();
    const generated: CommunicationDraftForm[] = [];
    fixture.componentInstance.generate.subscribe((value) => generated.push(value));

    (fixture.nativeElement as HTMLElement).querySelector<HTMLFormElement>("form")?.dispatchEvent(new Event("submit"));

    expect(generated).toEqual([expect.objectContaining({
      tone: "Chiaro e diretto",
      style: "Testo informativo"
    })]);
  });

  it("non emette e marca il prompt quando il form non e' valido", () => {
    const fixture = render();
    const generated: CommunicationDraftForm[] = [];
    fixture.componentInstance.generate.subscribe((value) => generated.push(value));
    fixture.componentInstance["form"].controls.prompt.setValue("breve");

    fixture.componentInstance["submit"]();

    expect(generated).toEqual([]);
    expect(fixture.componentInstance["form"].controls.prompt.touched).toBe(true);
  });

  it("riflette generazione, fase e stato negli input", () => {
    const element = render({ isGenerating: true, phase: "generating-text", status: "Testo in corso" })
      .nativeElement as HTMLElement;

    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(true);
    expect(element.textContent).toContain("Generazione");
    expect(element.textContent).toContain("Testo in corso");
    expect(element.querySelector("mvp-generation-progress")).not.toBeNull();
  });

  it("nasconde il progresso nella fase idle", () => {
    const element = render({ phase: "idle" }).nativeElement as HTMLElement;

    expect(element.querySelector("mvp-generation-progress")).toBeNull();
  });
});
