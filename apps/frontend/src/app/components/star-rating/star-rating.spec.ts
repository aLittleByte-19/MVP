import { ComponentFixture, TestBed } from '@angular/core/testing';

import { StarRating } from './star-rating';

describe('StarRating', () => {
  let component: StarRating;
  let fixture: ComponentFixture<StarRating>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [StarRating]
    })
    .compileComponents();

    fixture = TestBed.createComponent(StarRating);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('mostra il punteggio ricevuto dal padre', () => {
    fixture.componentRef.setInput('rating', 3);
    fixture.detectChanges();

    const stars = [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')];
    expect(stars.filter((star) => star.classList.contains('active'))).toHaveLength(3);
  });

  it('anticipa il punteggio al passaggio del mouse e lo ripristina all uscita', () => {
    fixture.componentRef.setInput('rating', 2);
    fixture.detectChanges();
    const stars = [...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('button')];

    stars[3].dispatchEvent(new MouseEvent('mouseenter'));
    fixture.detectChanges();
    expect(stars.filter((star) => star.classList.contains('active'))).toHaveLength(4);

    stars[3].dispatchEvent(new MouseEvent('mouseleave'));
    fixture.detectChanges();
    expect(stars.filter((star) => star.classList.contains('active'))).toHaveLength(2);
  });

  it('emette la stella scelta senza modificare autonomamente il rating', () => {
    const ratings: number[] = [];
    component.rated.subscribe((rating) => ratings.push(rating));
    const stars = [...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('button')];

    stars[4].click();

    expect(ratings).toEqual([5]);
    expect(component.rating()).toBe(0);
  });

  it('non reagisce quando e disabilitato', () => {
    const ratings: number[] = [];
    component.rated.subscribe((rating) => ratings.push(rating));
    fixture.componentRef.setInput('disabled', true);
    fixture.detectChanges();
    const stars = [...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('button')];

    stars[2].dispatchEvent(new MouseEvent('mouseenter'));
    component['rate'](3);

    expect(stars.every((star) => star.disabled)).toBe(true);
    expect(component['hoverState']()).toBe(0);
    expect(ratings).toEqual([]);
  });
});
