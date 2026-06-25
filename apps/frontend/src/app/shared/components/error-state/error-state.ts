import { ChangeDetectionStrategy, Component, input } from "@angular/core";
import { AlertComponent } from "../alert/alert";

@Component({
  selector: "poc-error-state",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AlertComponent],
  template: `<poc-alert [title]="title()">{{ message() }}</poc-alert>`
})
export class ErrorStateComponent {
  readonly message = input.required<string>();
  readonly title = input<string>("Servizio non disponibile");
}
