import { Component } from "@angular/core";
import { TestBed } from "@angular/core/testing";
import { StatusDotComponent } from "./status-dot";

@Component({
  imports: [StatusDotComponent],
  template: `<mvp-status-dot [done]="done">Scaricato</mvp-status-dot>`
})
class HostComponent {
  done = false;
}

describe("StatusDotComponent", () => {
  function render(done: boolean): HTMLElement {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.done = done;
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it("proietta l'etichetta, che resta il portatore dell'informazione", () => {
    expect(render(false).textContent).toContain("Scaricato");
  });

  it("marca come compiuto solo lo stato compiuto", () => {
    expect(render(true).querySelector(".indicator")?.classList).toContain("done");
    expect(render(false).querySelector(".indicator")?.classList).not.toContain("done");
  });

  it("nasconde il pallino alle tecnologie assistive, che leggono gia' l'etichetta", () => {
    expect(render(true).querySelector(".dot")?.getAttribute("aria-hidden")).toBe("true");
  });
});
