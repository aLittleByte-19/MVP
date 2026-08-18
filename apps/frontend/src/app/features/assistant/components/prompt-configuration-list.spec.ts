import { TestBed } from "@angular/core/testing";
import type { PromptConfiguration } from "../../../../api/generated/model";
import { PromptConfigurationListComponent } from "./prompt-configuration-list";

/** Configurazione minima valida secondo il contratto, personalizzabile. */
function configuration(overrides: Partial<PromptConfiguration> = {}): PromptConfiguration {
  return {
    id: 1,
    name: "Onboarding",
    prompt: "Scrivi una comunicazione di benvenuto",
    tone: "Chiaro e diretto",
    style: "Testo informativo",
    createdAt: "2026-08-17",
    ...overrides
  };
}

describe("PromptConfigurationListComponent", () => {
  function render(inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(PromptConfigurationListComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture;
  }

  it("non occupa spazio finche' non c'e' almeno una configurazione", () => {
    const host = render({ configurations: [] }).nativeElement as HTMLElement;

    expect(host.querySelector(".savedConfigs")).toBeNull();
  });

  it("intitola il gruppo con un heading, non con un testo che ne ha solo l'aspetto", () => {
    // Uno <span> in grassetto non entra nella struttura del documento e chi
    // naviga per intestazioni non lo incontra (WCAG 1.3.1).
    const host = render({ configurations: [configuration()] }).nativeElement as HTMLElement;

    expect(host.querySelector("h3")?.textContent?.trim()).toBe("Configurazioni salvate");
  });

  it("mostra nome e data di ogni configurazione", () => {
    const host = render({
      configurations: [configuration(), configuration({ id: 2, name: "Chiusura estiva" })]
    }).nativeElement as HTMLElement;

    expect(host.querySelectorAll(".savedConfig")).toHaveLength(2);
    expect(host.textContent).toContain("Onboarding");
    expect(host.textContent).toContain("Chiusura estiva");
    // La data arriva in ISO dal backend e va letta in formato italiano.
    expect(host.textContent).toContain("17/08/2026");
  });

  it("propaga al padre la configurazione da riusare, intera", () => {
    const fixture = render({ configurations: [configuration()] });
    const host = fixture.nativeElement as HTMLElement;
    const used = jest.fn();
    fixture.componentInstance.useRequested.subscribe(used);

    Array.from(host.querySelectorAll<HTMLButtonElement>("button"))
      .find((button) => button.textContent?.trim() === "Usa")
      ?.click();

    expect(used).toHaveBeenCalledWith(expect.objectContaining({ id: 1, name: "Onboarding" }));
  });

  it("chiede conferma prima di eliminare e la annulla senza eliminare", () => {
    const fixture = render({ configurations: [configuration()], confirmingDeleteId: 1 });
    const host = fixture.nativeElement as HTMLElement;
    const confirmRequested = jest.fn();
    const deleteRequested = jest.fn();
    fixture.componentInstance.confirmDeleteRequested.subscribe(confirmRequested);
    fixture.componentInstance.deleteRequested.subscribe(deleteRequested);

    expect(host.textContent).toContain("Eliminare definitivamente questa configurazione salvata?");

    const [conferma, annulla] = Array.from(
      host.querySelectorAll<HTMLButtonElement>(".cardActions button")
    );
    annulla?.click();
    expect(confirmRequested).toHaveBeenCalledWith(null);
    expect(deleteRequested).not.toHaveBeenCalled();

    conferma?.click();
    expect(deleteRequested).toHaveBeenCalledWith(1);
  });

  it("mostra la conferma solo sulla riga che la sta chiedendo", () => {
    const host = render({
      configurations: [configuration(), configuration({ id: 2 })],
      confirmingDeleteId: 2
    }).nativeElement as HTMLElement;

    expect(host.querySelectorAll(".cardConfirm")).toHaveLength(1);
  });
});
