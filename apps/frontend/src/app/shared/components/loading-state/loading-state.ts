import { ChangeDetectionStrategy, Component, input } from "@angular/core";

@Component({
  selector: "mvp-loading-state",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<p class="loading" role="status" aria-live="polite">{{ label() }}</p>`,
  styleUrl: "./loading-state.css"
})
export class LoadingStateComponent {
  readonly label = input<string>("Caricamento in corso.");
}
