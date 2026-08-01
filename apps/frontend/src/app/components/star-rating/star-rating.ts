import { ChangeDetectionStrategy, Component, input, output, signal } from "@angular/core";

@Component({
  selector: "mvp-star-rating",
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: "./star-rating.html",
  styleUrl: "./star-rating.css"
})
export class StarRating {
  readonly rating = input(0);
  readonly disabled = input(false);
  readonly rated = output<number>();

  /** Stella sotto il cursore: anteprima del punteggio prima del click. */
  protected readonly hoverState = signal(0);

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
