import { ChangeDetectionStrategy, Component, computed, input, output, signal } from "@angular/core";

/** Scala fissa a cinque valori: nessun mezzo punto, quindi nessun cursore. */
const STARS = [1, 2, 3, 4, 5] as const;

/**
 * Valutazione a stelle: gruppo di radio (pattern ARIA APG per scala a valori discreti), non `<button>` sciolti.
 * Il contorno resta visibile in entrambi gli stati e il pieno si aggiunge, cosi' chi non distingue i colori legge comunque il punteggio (SC 1.4.1).
 */
@Component({
  selector: "mvp-star-rating",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="rating">
      <fieldset class="stars">
        <legend class="srOnly">{{ label() }}</legend>
        @for (star of stars; track star) {
          <input
            class="srOnly"
            type="radio"
            [id]="inputId(star)"
            [name]="groupName"
            [checked]="star === rating()"
            [disabled]="disabled()"
            (change)="rate(star)"
          />
          <label
            [for]="inputId(star)"
            [class.filled]="star <= shownRating()"
            (mouseenter)="setHover(star)"
            (mouseleave)="setHover(0)"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path
                d="M12 2.6l2.9 5.88 6.49.95-4.7 4.57 1.11 6.47L12 17.42l-5.8 3.05 1.11-6.47-4.7-4.57 6.49-.95z"
              />
            </svg>
            <span class="srOnly">{{ star }} {{ star === 1 ? "stella" : "stelle" }} su 5</span>
          </label>
        }
      </fieldset>
      <output class="score" [for]="groupName" aria-live="polite">
        @if (rating()) {
          {{ rating() }}/5
        } @else {
          <span class="pending">Nessun punteggio</span>
        }
      </output>
    </div>
  `,
  styleUrl: "./star-rating.css"
})
export class StarRating {
  private static sequence = 0;

  readonly rating = input(0);
  readonly disabled = input(false);
  readonly label = input("Valutazione da 1 a 5 stelle");
  readonly rated = output<number>();

  protected readonly stars = STARS;

  /** Il gruppo di radio va isolato: piu' bozze possono convivere nel DOM. */
  protected readonly groupName = `mvp-star-rating-${(StarRating.sequence += 1)}`;

  /** Stella sotto il cursore: anteprima del punteggio prima del click. */
  protected readonly hoverState = signal(0);

  protected readonly shownRating = computed(() => this.hoverState() || this.rating());

  protected inputId(star: number): string {
    return `${this.groupName}-${star}`;
  }

  protected rate(star: number): void {
    if (this.disabled()) {
      return;
    }

    // Il punteggio mostrato resta guidato dal padre: qui si emette soltanto.
    this.rated.emit(star);
  }

  protected setHover(star: number): void {
    if (this.disabled()) {
      return;
    }

    this.hoverState.set(star);
  }
}
