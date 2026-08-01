import { ChangeDetectionStrategy, Component, input } from "@angular/core";

/**
 * Contenitore "card" dell'app. L'host stesso e' la sezione stilizzata, cosi' gli
 * id per l'ancoraggio e le classi modificatore applicate dal chiamante (es.
 * `hero`, `form`) restano nel suo scope e funzionano sotto la view encapsulation.
 * L'intestazione (titolo + azioni) compare solo quando e' presente un titolo.
 */
@Component({
  selector: "mvp-section",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (title()) {
      <div class="heading">
        <h2>{{ title() }}</h2>
        <ng-content select="[actions]" />
      </div>
    }
    <ng-content />
  `,
  styleUrl: "./section.css",
  host: {
    "[attr.id]": "id() || null"
  }
})
export class SectionComponent {
  readonly title = input<string>();
  readonly id = input<string>();
}
