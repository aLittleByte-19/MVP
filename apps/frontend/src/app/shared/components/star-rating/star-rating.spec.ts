import { ComponentFixture, TestBed } from "@angular/core/testing";
import { StarRating } from "./star-rating";

describe("StarRating", () => {
  let component: StarRating;
  let fixture: ComponentFixture<StarRating>;

  function labels(): HTMLLabelElement[] {
    return [...(fixture.nativeElement as HTMLElement).querySelectorAll("label")];
  }

  function radios(): HTMLInputElement[] {
    return [...(fixture.nativeElement as HTMLElement).querySelectorAll("input")];
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [StarRating] }).compileComponents();

    fixture = TestBed.createComponent(StarRating);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it("espone una scala di cinque valori con un nome per ciascuno", () => {
    // Il gruppo di radio e' il pattern che l'APG indica per una scala
    // discreta: senza nome accessibile sarebbero cinque comandi identici.
    expect(radios()).toHaveLength(5);
    expect(labels()[2].textContent).toContain("3 stelle su 5");
    expect(labels()[0].textContent).toContain("1 stella su 5");
  });

  it("riempie le stelle fino al punteggio ricevuto dal padre", () => {
    fixture.componentRef.setInput("rating", 3);
    fixture.detectChanges();

    expect(labels().filter((label) => label.classList.contains("filled"))).toHaveLength(3);
  });

  it("mostra il punteggio come X/5 e dice esplicitamente quando manca", () => {
    expect((fixture.nativeElement as HTMLElement).textContent).toContain("Nessun punteggio");

    fixture.componentRef.setInput("rating", 4);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain("4/5");
  });

  it("anticipa il punteggio al passaggio del mouse e lo ripristina all'uscita", () => {
    fixture.componentRef.setInput("rating", 2);
    fixture.detectChanges();

    labels()[3].dispatchEvent(new MouseEvent("mouseenter"));
    fixture.detectChanges();
    expect(labels().filter((label) => label.classList.contains("filled"))).toHaveLength(4);

    labels()[3].dispatchEvent(new MouseEvent("mouseleave"));
    fixture.detectChanges();
    expect(labels().filter((label) => label.classList.contains("filled"))).toHaveLength(2);
  });

  it("emette la stella scelta senza modificare autonomamente il rating", () => {
    const ratings: number[] = [];
    component.rated.subscribe((rating) => ratings.push(rating));

    radios()[4].click();

    expect(ratings).toEqual([5]);
    expect(component.rating()).toBe(0);
  });

  it("non reagisce quando e' disabilitato", () => {
    const ratings: number[] = [];
    component.rated.subscribe((rating) => ratings.push(rating));
    fixture.componentRef.setInput("disabled", true);
    fixture.detectChanges();

    labels()[2].dispatchEvent(new MouseEvent("mouseenter"));
    radios()[2].click();

    expect(radios().every((radio) => radio.disabled)).toBe(true);
    expect(component["hoverState"]()).toBe(0);
    expect(ratings).toEqual([]);
  });

  it("isola il gruppo, cosi' due valutazioni nella stessa pagina non si scambiano il punteggio", () => {
    const other = TestBed.createComponent(StarRating);
    other.detectChanges();

    expect(radios()[0].name).not.toBe(
      (other.nativeElement as HTMLElement).querySelector("input")?.name
    );
  });
});
