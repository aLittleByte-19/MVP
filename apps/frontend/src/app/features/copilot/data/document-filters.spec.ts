import { Component } from "@angular/core";
import { ReactiveFormsModule } from "@angular/forms";
import { TestBed } from "@angular/core/testing";
import { createDocumentFilterForm, toDocumentFilters } from "./document-filters";

/**
 * Host minimale che replica i binding reali della barra filtri: i due campi
 * numerici devono passare da un `input[type=number]`, perche' e' li' che
 * Angular applica NumberValueAccessor e scrive un `number` nel controllo.
 * Un test che impostasse i valori solo via `setValue` non riprodurrebbe il
 * problema che questa suite deve impedire.
 */
@Component({
  standalone: true,
  imports: [ReactiveFormsModule],
  template: `
    <form [formGroup]="form">
      <input id="search" type="text" formControlName="search" />
      <select id="sendStatus" formControlName="sendStatus">
        <option value=""></option>
        <option value="sent">sent</option>
      </select>
      <select id="criterion" formControlName="confidenceCriterion">
        <option value="below">below</option>
        <option value="above">above</option>
      </select>
      <input id="threshold" type="number" formControlName="confidenceThreshold" />
      <select id="month" formControlName="month">
        <option value=""></option>
        <option value="3">marzo</option>
      </select>
      <input id="year" type="number" formControlName="year" />
    </form>
  `
})
class FilterHost {
  readonly form = createDocumentFilterForm();
}

describe("filtri dello storico documenti", () => {
  let host: FilterHost;
  let fixture: ReturnType<typeof TestBed.createComponent<FilterHost>>;

  function typeInto(id: string, value: string): void {
    const input = fixture.nativeElement.querySelector(`#${id}`) as HTMLInputElement | HTMLSelectElement;
    input.value = value;
    input.dispatchEvent(new Event(id === "sendStatus" || id === "criterion" || id === "month" ? "change" : "input"));
    fixture.detectChanges();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [FilterHost] });
    fixture = TestBed.createComponent(FilterHost);
    host = fixture.componentInstance;
    fixture.detectChanges();
  });

  it("non produce criteri quando il form e' vuoto", () => {
    expect(toDocumentFilters(host.form.value)).toEqual({});
  });

  it("mappa la soglia digitata senza sollevare, anche se il controllo contiene un numero", () => {
    typeInto("threshold", "80");

    // Il valore scritto da NumberValueAccessor e' un number: e' la regressione
    // che questa asserzione protegge (prima qui si chiamava .trim()).
    expect(typeof host.form.controls.confidenceThreshold.value).toBe("number");
    expect(() => toDocumentFilters(host.form.value)).not.toThrow();
    expect(toDocumentFilters(host.form.value)).toEqual({
      confidenceThreshold: 80,
      confidenceCriterion: "below"
    });
  });

  it("porta con se' il criterio scelto accanto alla soglia", () => {
    typeInto("threshold", "60");
    typeInto("criterion", "above");

    expect(toDocumentFilters(host.form.value)).toEqual({
      confidenceThreshold: 60,
      confidenceCriterion: "above"
    });
  });

  it("mappa l'anno digitato, anch'esso numerico", () => {
    typeInto("year", "2026");

    expect(typeof host.form.controls.year.value).toBe("number");
    expect(toDocumentFilters(host.form.value)).toEqual({ year: 2026 });
  });

  it("ignora i campi numerici svuotati invece di inviarli come NaN", () => {
    typeInto("threshold", "80");
    typeInto("threshold", "");

    expect(toDocumentFilters(host.form.value)).toEqual({});
  });

  it("compone i criteri testuali, di stato e di periodo", () => {
    typeInto("search", "  Ferrari  ");
    typeInto("sendStatus", "sent");
    typeInto("month", "3");

    expect(toDocumentFilters(host.form.value)).toEqual({
      search: "Ferrari",
      sendStatus: "sent",
      month: 3
    });
  });

  it("azzera tutti i criteri quando il form viene resettato", () => {
    typeInto("search", "Ferrari");
    typeInto("threshold", "80");
    host.form.reset();
    fixture.detectChanges();

    expect(toDocumentFilters(host.form.value)).toEqual({});
  });
});
