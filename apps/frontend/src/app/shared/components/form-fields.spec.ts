import { FormControl } from "@angular/forms";
import { TestBed } from "@angular/core/testing";
import { SelectFieldComponent } from "./select-field/select-field";
import { TextAreaFieldComponent } from "./textarea-field/textarea-field";

/**
 * I due campi condivisi dei form. Quello che va garantito e' l'aggancio fra
 * `<label>` e controllo: senza un id coerente il campo resta senza etichetta
 * per uno screen reader, e la CI ha un gate di accessibilita' che se ne accorge
 * solo a pagina montata, quindi tardi.
 */
describe("SelectFieldComponent", () => {
  function render(inputs: Record<string, unknown>) {
    const fixture = TestBed.createComponent(SelectFieldComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("collega l'etichetta al controllo tramite un id derivato dall'etichetta", () => {
    const element = render({
      label: "Stato invio",
      options: ["sent", "pending"],
      control: new FormControl("", { nonNullable: true })
    });

    const select = element.querySelector("select") as HTMLSelectElement;
    const label = element.querySelector("label") as HTMLLabelElement;

    expect(select.id).toBe("field-stato-invio");
    expect(label.htmlFor).toBe(select.id);
  });

  it("preferisce l'id esplicito quando viene passato", () => {
    const element = render({
      label: "Stato invio",
      options: [],
      control: new FormControl("", { nonNullable: true }),
      id: "filtro-stato"
    });

    expect((element.querySelector("select") as HTMLSelectElement).id).toBe("filtro-stato");
  });

  it("rende una opzione per ogni valore ammesso", () => {
    const element = render({
      label: "Tipologia",
      options: ["cedolino", "CU", "lettera"],
      control: new FormControl("", { nonNullable: true })
    });

    const options = [...element.querySelectorAll("option")].map((option) => option.textContent?.trim());

    expect(options).toEqual(["cedolino", "CU", "lettera"]);
  });

  it("riflette il valore del controllo e lo aggiorna quando cambia la selezione", () => {
    const control = new FormControl("cedolino", { nonNullable: true });
    const element = render({ label: "Tipologia", options: ["cedolino", "CU"], control });
    const select = element.querySelector("select") as HTMLSelectElement;

    expect(select.value).toBe("cedolino");

    select.value = "CU";
    select.dispatchEvent(new Event("change"));

    expect(control.value).toBe("CU");
  });
});

describe("TextAreaFieldComponent", () => {
  function render(inputs: Record<string, unknown>) {
    const fixture = TestBed.createComponent(TextAreaFieldComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("collega l'etichetta al controllo", () => {
    const element = render({ label: "Messaggio", control: new FormControl("", { nonNullable: true }) });

    const textarea = element.querySelector("textarea") as HTMLTextAreaElement;

    expect(textarea.id).toBe("field-messaggio");
    expect((element.querySelector("label") as HTMLLabelElement).htmlFor).toBe(textarea.id);
  });

  it("usa quattro righe se non viene chiesto altrimenti", () => {
    const element = render({ label: "Messaggio", control: new FormControl("", { nonNullable: true }) });

    expect((element.querySelector("textarea") as HTMLTextAreaElement).rows).toBe(4);
  });

  it("rispetta il numero di righe e il testo di aiuto richiesti", () => {
    const element = render({
      label: "Prompt",
      control: new FormControl("", { nonNullable: true }),
      rows: 8,
      placeholder: "Descrivi la comunicazione"
    });

    const textarea = element.querySelector("textarea") as HTMLTextAreaElement;

    expect(textarea.rows).toBe(8);
    expect(textarea.placeholder).toBe("Descrivi la comunicazione");
  });

  it("propaga al controllo quello che l'utente digita", () => {
    const control = new FormControl("", { nonNullable: true });
    const element = render({ label: "Messaggio", control });
    const textarea = element.querySelector("textarea") as HTMLTextAreaElement;

    textarea.value = "In allegato il cedolino.";
    textarea.dispatchEvent(new Event("input"));

    expect(control.value).toBe("In allegato il cedolino.");
  });
});
