import { ChangeDetectionStrategy, Component, input } from "@angular/core";

export type ButtonVariant = "primary" | "secondary" | "icon";

/**
 * Pulsante dell'app applicato come attributo su un `<button>` nativo
 * (`<button mvpButton variant="secondary">`). Cosi' restano nativi tipo,
 * stato disabilitato, attributi ARIA ed eventi, mentre la variante guida lo
 * stile sull'host.
 *
 * `busy` segnala che il comando e' in corso senza toccarne l'etichetta. Prima
 * ogni pulsante riscriveva la propria — "Salvataggio", "Salvataggio…",
 * "Salvataggio in corso…", "Invio in corso…" — e il comando cambiava nome
 * mentre lo si guardava: chi torna sulla pagina non ritrova la parola che
 * aveva cercato, e la larghezza salta a meta' clic. `aria-busy` dice la stessa
 * cosa alle tecnologie assistive.
 */
@Component({
  // eslint-disable-next-line @angular-eslint/component-selector
  selector: "button[mvpButton]",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<ng-content /><span class="spinner" [class.on]="busy()" aria-hidden="true"></span>`,
  styleUrl: "./button.css",
  host: {
    "[class.primary]": "variant() === 'primary'",
    "[class.secondary]": "variant() === 'secondary'",
    "[class.icon]": "variant() === 'icon'",
    "[attr.type]": "type()",
    "[attr.aria-busy]": "busy() ? 'true' : null"
  }
})
export class ButtonComponent {
  readonly variant = input<ButtonVariant>("primary");
  readonly type = input<string>("button");
  readonly busy = input(false);
}
