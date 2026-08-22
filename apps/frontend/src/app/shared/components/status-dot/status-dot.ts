import { ChangeDetectionStrategy, Component, input } from "@angular/core";

/**
 * Indicatore binario: una cosa e' fatta oppure non lo e'.
 *
 * Sta accanto all'etichetta rettangolare dello stato di validazione senza
 * confondersi con essa (ADR 0012): il rettangolo dice *in che stato* si trova
 * la revisione, che ha quattro valori; il pallino dice *se* il documento e'
 * stato scaricato, che ne ha due. Due forme diverse per due domande diverse,
 * cosi' una riga di tabella non si legge come una fila di quattro etichette
 * uguali.
 *
 * Pieno contro vuoto non e' solo colore: SC 1.4.1 chiede che l'informazione
 * non passi dal colore soltanto, e il testo resta comunque il portatore
 * principale. Il pallino e' `aria-hidden` perche' ripete cio' che l'etichetta
 * gia' dice.
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
