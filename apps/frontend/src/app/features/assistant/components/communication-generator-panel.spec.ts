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

  it("chiude il modulo di salvataggio configurazione se si genera una nuova bozza invece di confermare", () => {
    const fixture = render();
    const component = fixture.componentInstance;
    component["isConfiguringName"].set(true);
    component["configNameControl"].setValue("Nome non confermato");

    component["submit"]();

    expect(component["isConfiguringName"]()).toBe(false);
    expect(component["configNameControl"].value).toBe("");
  });

  it("riflette generazione, fase e stato negli input", () => {
    const element = render({ isGenerating: true, phase: "generating-text", status: "Testo in corso" })
      .nativeElement as HTMLElement;

    // L'etichetta non cambia mentre il comando lavora: lo stato lo dice
    // `aria-busy`, non una parola diversa al posto di quella cercata.
    const submit = element.querySelector<HTMLButtonElement>('button[type="submit"]');

    expect(submit?.disabled).toBe(true);
    expect(submit?.getAttribute("aria-busy")).toBe("true");
    expect(submit?.textContent).toContain("Genera bozza");
    expect(element.textContent).toContain("Testo in corso");
    expect(element.querySelector("mvp-generation-progress")).not.toBeNull();
  });

  it("nasconde il progresso nella fase idle", () => {
    const element = render({ phase: "idle" }).nativeElement as HTMLElement;

    expect(element.querySelector("mvp-generation-progress")).toBeNull();
  });

  it("salva la configurazione corrente con o senza nome (UC-19)", () => {
    const fixture = render();
    const component = fixture.componentInstance;
    const saved: unknown[] = [];
    component.saveConfiguration.subscribe((value) => saved.push(value));

    component["isConfiguringName"].set(true);
    component["configNameControl"].setValue("  La mia config  ");
    component["confirmSaveConfiguration"]();

    component["isConfiguringName"].set(true);
    component["configNameControl"].setValue("   ");
    component["confirmSaveConfiguration"]();

    expect(saved).toEqual([
      expect.objectContaining({ name: "La mia config", tone: "Chiaro e diretto", style: "Testo informativo" }),
      expect.objectContaining({ name: undefined })
    ]);
  });

  it("non salva la configurazione se il prompt e' insufficiente", () => {
    const fixture = render();
    const component = fixture.componentInstance;
    const saved: unknown[] = [];
    component.saveConfiguration.subscribe((value) => saved.push(value));
    component["form"].controls.prompt.setValue("breve");

    component["confirmSaveConfiguration"]();

    expect(saved).toEqual([]);
    expect(component["form"].controls.prompt.touched).toBe(true);
  });

  it("richiude il modulo del nome quando l'elenco delle configurazioni salvate cambia", () => {
    const fixture = render({ promptConfigurations: [] });
    const component = fixture.componentInstance;
    component["isConfiguringName"].set(true);

    fixture.componentRef.setInput("promptConfigurations", [
      { id: 1, name: "Prima config", prompt: "Un prompt qualsiasi", tone: "Chiaro e diretto", style: "Testo informativo" }
    ]);
    fixture.detectChanges();

    expect(component["isConfiguringName"]()).toBe(false);
  });

  it("applica i valori di prefill ricevuti dal genitore (riuso di una configurazione salvata, UC-19)", () => {
    const fixture = render();

    fixture.componentRef.setInput("prefill", {
      prompt: "Prompt riusato da una configurazione salvata",
      tone: "Tecnico",
      style: "Avviso operativo"
    });
    fixture.detectChanges();

    expect(fixture.componentInstance["form"].getRawValue()).toEqual({
      prompt: "Prompt riusato da una configurazione salvata",
      tone: "Tecnico",
      style: "Avviso operativo"
    });
  });
});
