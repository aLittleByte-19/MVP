import { ChangeDetectionStrategy, Component, input } from "@angular/core";
import { AlertComponent } from "../alert/alert";

@Component({
  selector: "mvp-error-state",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AlertComponent],
  template: `<mvp-alert [title]="title()">{{ message() }}</mvp-alert>`
})
export class ErrorStateComponent {
  readonly message = input.required<string>();
  readonly title = input<string>("Servizio non disponibile");
}
