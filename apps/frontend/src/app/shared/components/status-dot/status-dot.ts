import { ChangeDetectionStrategy, Component, input } from "@angular/core";

/**
 * Indicatore binario (ADR 0012), diverso dall'etichetta rettangolare dello stato di validazione: forme diverse per domande diverse (a quattro vs due valori).
 * Pieno/vuoto, non solo colore (SC 1.4.1). `aria-hidden`: ripete cio' che l'etichetta gia' dice.
 */
@Component({
  selector: "mvp-status-dot",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="indicator" [class.done]="done()"
    ><span class="dot" aria-hidden="true"></span><ng-content
  /></span>`,
  styleUrl: "./status-dot.css"
})
export class StatusDotComponent {
  readonly done = input(false);
}
