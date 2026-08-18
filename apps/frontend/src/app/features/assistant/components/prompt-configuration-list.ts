import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import { LucideTrash2 } from "@lucide/angular";
import type { PromptConfiguration } from "../../../../api/generated/model";
import { ButtonComponent } from "../../../shared/components/button/button";
import { formatDateForDisplay } from "../../../shared/util/formatters";

/**
 * Configurazioni di prompt salvate (UC-19): riuso nel form di generazione ed
 * eliminazione con conferma. Non rende nulla finche' non ce n'e' almeno una.
 *
 * Come per lo storico, lo stato della conferma resta nel ViewModel della
 * pagina, che lo azzera al termine dell'eliminazione.
 */
@Component({
  selector: "mvp-prompt-configuration-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, LucideTrash2],
  template: `
    @if (configurations().length) {
      <div class="savedConfigs">
        <h3 class="savedConfigsTitle">Configurazioni salvate</h3>
        @for (configuration of configurations(); track configuration.id) {
          <div class="savedConfig">
            <div class="savedConfigHeader">
              <span>
                {{ configuration.name }}
                <span class="savedConfigDate">{{ formatDateForDisplay(configuration.createdAt) }}</span>
              </span>
              <div class="savedConfigActions">
                <button mvpButton variant="secondary" type="button" (click)="useRequested.emit(configuration)">
                  Usa
                </button>
                @if (confirmingDeleteId() !== configuration.id) {
                  <button
                    mvpButton
                    variant="icon"
                    type="button"
                    aria-label="Elimina configurazione salvata"
                    [disabled]="isDeleting()"
                    (click)="confirmDeleteRequested.emit(configuration.id)"
                  >
                    <svg lucideTrash2 aria-hidden="true"></svg>
                  </button>
                }
              </div>
            </div>
            @if (confirmingDeleteId() === configuration.id) {
              <div class="cardConfirm">
                <p class="warning" role="status">La configurazione viene rimossa in modo definitivo.</p>
                <div class="cardActions">
                  <button
                    mvpButton
                    type="button"
                    [disabled]="isDeleting()"
                    (click)="deleteRequested.emit(configuration.id)"
                  >
                    Conferma eliminazione
                  </button>
                  <button
                    mvpButton
                    type="button"
                    variant="secondary"
                    [disabled]="isDeleting()"
                    (click)="confirmDeleteRequested.emit(null)"
                  >
                    Annulla
                  </button>
                </div>
              </div>
            }
          </div>
        }
      </div>
    }
  `,
  styleUrls: ["../../../shared/styles/notice.css", "./prompt-configuration-list.css"]
})
export class PromptConfigurationListComponent {
  readonly configurations = input<PromptConfiguration[]>([]);
  readonly confirmingDeleteId = input<number | null>(null);
  readonly isDeleting = input(false);

  readonly useRequested = output<PromptConfiguration>();
  readonly confirmDeleteRequested = output<number | null>();
  readonly deleteRequested = output<number>();

  protected readonly formatDateForDisplay = formatDateForDisplay;
}
