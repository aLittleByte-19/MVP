import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";

/** Stato visivo della barra, indipendente dal dominio che la usa. */
export type ProgressState = "idle" | "active" | "done" | "error";

/**
 * Barra di avanzamento orizzontale condivisa fra le pipeline asincrone. Non
 * conosce le fasi dei singoli domini: riceve gia' la percentuale e lo stato, e
 * ogni feature mappa la propria fase (elaborazione documentale, generazione
 * della comunicazione). Finche' lo stato e' `active` un bagliore scorre da
 * sinistra a destra; con `prefers-reduced-motion` resta fermo (WCAG 2.3.3).
 */
@Component({
  selector: "mvp-progress-bar",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="progress"
      role="progressbar"
      [attr.aria-label]="label()"
      aria-valuemin="0"
      aria-valuemax="100"
      [attr.aria-valuenow]="clamped()"
      [class.isActive]="state() === 'active'"
      [class.isDone]="state() === 'done'"
      [class.isError]="state() === 'error'"
    >
      <div class="track">
        <div class="fill" [style.width.%]="clamped()"></div>
      </div>
    </div>
  `,
  styleUrl: "./progress-bar.css"
})
export class ProgressBarComponent {
  readonly value = input.required<number>();
  readonly state = input<ProgressState>("idle");
  readonly label = input("Avanzamento elaborazione");

  protected readonly clamped = computed(() => Math.min(100, Math.max(0, this.value())));
}
