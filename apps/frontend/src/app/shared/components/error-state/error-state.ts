import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import { AlertComponent } from "../alert/alert";
import { ButtonComponent } from "../button/button";

@Component({
  selector: "mvp-error-state",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AlertComponent, ButtonComponent],
  template: `
    <mvp-alert [title]="title()">
      {{ message() }}
      @if (canRetry()) {
        <button mvpButton type="button" variant="secondary" (click)="retry.emit()">Riprova</button>
      }
    </mvp-alert>
  `
})
export class ErrorStateComponent {
  readonly message = input.required<string>();
  readonly title = input<string>("Servizio non disponibile");
  readonly canRetry = input(false);
  readonly retry = output<void>();
}
