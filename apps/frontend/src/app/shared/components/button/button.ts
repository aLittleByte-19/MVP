import { ChangeDetectionStrategy, Component, input } from "@angular/core";

export type ButtonVariant = "primary" | "secondary" | "icon";

/**
 * Pulsante dell'app applicato come attributo su un `<button>` nativo
 * (`<button mvpButton variant="secondary">`). Cosi' restano nativi tipo,
 * stato disabilitato, attributi ARIA ed eventi, mentre la variante guida lo
 * stile sull'host.
 */
@Component({
  // eslint-disable-next-line @angular-eslint/component-selector
  selector: "button[mvpButton]",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: "<ng-content />",
  styleUrl: "./button.css",
  host: {
    "[class.primary]": "variant() === 'primary'",
    "[class.secondary]": "variant() === 'secondary'",
    "[class.icon]": "variant() === 'icon'",
    "[attr.type]": "type()"
  }
})
export class ButtonComponent {
  readonly variant = input<ButtonVariant>("primary");
  readonly type = input<string>("button");
}
