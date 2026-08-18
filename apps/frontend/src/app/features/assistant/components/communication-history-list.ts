import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import { LucideStar, LucideTrash2 } from "@lucide/angular";
import type { Communication } from "../../../../api/generated/model";
import { ButtonComponent } from "../../../shared/components/button/button";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { formatFallback } from "../../../shared/util/formatters";

/**
 * Elenco delle comunicazioni salvate nello storico: selezione per l'anteprima,
 * preferiti ed eliminazione con conferma.
 *
 * Lo stato della conferma non vive qui ma nel ViewModel della pagina, che lo
 * azzera al termine dell'eliminazione: il componente riceve quale riga sta
 * chiedendo conferma e si limita a dichiarare l'intenzione dell'utente.
 */
@Component({
  selector: "mvp-communication-history-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, EmptyStateComponent, LucideStar, LucideTrash2, StatusBadgeComponent],
  template: `
    @if (communications().length) {
      @for (communication of communications(); track communication.id) {
        <div class="card" [class.isSelected]="communication.id === selectedId()">
          <button
            type="button"
            class="cardSelect"
            [attr.aria-pressed]="communication.id === selectedId()"
            (click)="selected.emit(communication.id)"
          >
            <mvp-status-badge>{{ communication.status }}</mvp-status-badge>
            <span class="title">{{ communication.title }}</span>
          </button>
          <div class="cardFooter">
            <p>{{ formatFallback(communication.createdAt) }}</p>
            <button
              mvpButton
              variant="icon"
              type="button"
              class="favoriteToggle"
              [class.isFavorite]="communication.isFavorite"
              [attr.aria-pressed]="communication.isFavorite"
              [attr.aria-label]="
                communication.isFavorite ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti'
              "
              [disabled]="togglingFavoriteId() === communication.id"
              (click)="favoriteToggled.emit(communication)"
            >
              <svg lucideStar aria-hidden="true"></svg>
            </button>
            @if (confirmingDeleteId() !== communication.id) {
              <button
                mvpButton
                variant="icon"
                type="button"
                aria-label="Elimina dallo storico"
                [disabled]="isDeleting()"
                (click)="confirmDeleteRequested.emit(communication.id)"
              >
                <svg lucideTrash2 aria-hidden="true"></svg>
              </button>
            }
          </div>
          @if (confirmingDeleteId() === communication.id) {
            <div class="cardConfirm">
              <p class="warning" role="status">Eliminare definitivamente questo elemento dallo storico?</p>
              <div class="cardActions">
                <button mvpButton type="button" [disabled]="isDeleting()" (click)="deleteRequested.emit(communication.id)">
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
    } @else if (hasActiveFilters()) {
      <mvp-empty-state>Nessuna comunicazione corrisponde ai filtri selezionati.</mvp-empty-state>
    } @else {
      <mvp-empty-state>Le bozze generate compariranno qui.</mvp-empty-state>
    }
  `,
  styleUrl: "./communication-history-list.css"
})
export class CommunicationHistoryListComponent {
  readonly communications = input<Communication[]>([]);
  readonly selectedId = input<number | null>(null);
  readonly confirmingDeleteId = input<number | null>(null);
  readonly isDeleting = input(false);
  readonly togglingFavoriteId = input<number | null>(null);
  /** Cambia il testo dello stato vuoto: "nessun risultato" non e' "niente da mostrare". */
  readonly hasActiveFilters = input(false);

  readonly selected = output<number>();
  readonly favoriteToggled = output<Communication>();
  readonly confirmDeleteRequested = output<number | null>();
  readonly deleteRequested = output<number>();

  protected readonly formatFallback = formatFallback;
}
