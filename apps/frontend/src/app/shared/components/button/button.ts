import { ChangeDetectionStrategy, Component, input } from "@angular/core";

export type ButtonVariant = "primary" | "secondary" | "icon";

/**
 * Pulsante applicato come attributo su un `<button>` nativo, cosi' restano nativi tipo, disabled, ARIA ed eventi mentre la variante guida solo lo stile.
 * `busy` segnala il comando in corso senza toccarne l'etichetta, cosi' la larghezza non salta a meta' clic; `aria-busy` dice la stessa cosa alle tecnologie assistive.
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
