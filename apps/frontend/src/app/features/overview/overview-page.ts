import { ChangeDetectionStrategy, Component, inject } from "@angular/core";
import { Router } from "@angular/router";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { ButtonComponent } from "../../shared/components/button/button";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { AttentionNoteComponent } from "../../shared/components/attention-note/attention-note";
import { MetricCardComponent } from "../../shared/components/metric-card/metric-card";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../layout/section/section";
import { formatFallback } from "../../shared/util/formatters";
import { scrollToElement } from "../../shared/util/scroll";
import { OverviewPageViewModel } from "./overview-page.view-model";

/**
 * View della Overview: nessuna logica di business qui, solo collante col
 * template e procacciamento delle dipendenze via `inject()` per costruire
 * {@link OverviewPageViewModel} — incluso `scrollToElement`, l'unica
 * operazione DOM richiesta dal ViewModel dopo la navigazione. Il template
 * legge esclusivamente `vm.*`: errore, caricamento e ricarica sono
 * pass-through esposti dal ViewModel, come in Copilot e Assistant (ADR 0011).
 */
@Component({
  selector: "mvp-overview-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    AttentionNoteComponent,
    ButtonComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    MetricCardComponent,
    SectionComponent,
    StatusBadgeComponent
  ],
  template: `
    <section class="view" aria-label="Overview operativa">
      @if (vm.error(); as error) {
        <mvp-error-state [message]="error" [canRetry]="true" (retry)="vm.reload()" />
      }

      <mvp-section class="hero">
        <p class="eyebrow">Console operativa</p>
        <h2>Gestione assistita di comunicazioni interne e documenti del personale.</h2>
        <p>
          NEXUM supporta redattori HR e operatori CdL nelle attività quotidiane: preparazione dei contenuti,
          classificazione documentale, verifica degli esiti e tracciamento delle consegne.
        </p>
        <div class="buttonRow">
          <button mvpButton type="button" (click)="vm.navigate('assistant', 'assistant-compose')">Crea contenuto</button>
          <button mvpButton variant="secondary" type="button" (click)="vm.navigate('copilot', 'copilot-upload')">
            Carica documenti
          </button>
        </div>
      </mvp-section>

      <mvp-section id="overview-priorities" title="Priorità essenziali">
        @if (vm.quarantined() > 0) {
          <mvp-attention-note
            tone="alert"
            [title]="vm.quarantined() + ' sotto-documenti in quarantena'"
            detail="L'estrazione non è affidabile: vanno riesaminati prima dell'invio."
            actionLabel="Apri il Co-Pilot"
            (acted)="vm.navigate('copilot', 'copilot-documents')"
          />
        }
        <div class="priorityGrid">
          @for (priority of vm.priorities(); track priority.key) {
            <mvp-metric-card
              [label]="priority.label"
              [value]="priority.value"
              [tone]="priority.tone"
              [outOf]="priority.outOf"
              [history]="priority.history"
              [isLoading]="vm.loading()"
              [context]="priority.context"
            />
          }
        </div>
      </mvp-section>

      <mvp-section id="overview-quality" title="Qualità degli strumenti">
        <div class="qualityGrid">
          <div class="qualityItem">
            <h3>AI Assistant</h3>
            @if (vm.assistantQuality(); as quality) {
              <mvp-metric-card
                [label]="quality.label"
                [value]="quality.value"
                [unit]="quality.unit"
                [isLoading]="vm.loading()"
                context="qualità percepita delle bozze"
              />
            }
            <button mvpButton variant="secondary" type="button" (click)="vm.navigate('assistant', 'assistant-metrics')">
              Vedi tutte le metriche
            </button>
          </div>
          <div class="qualityItem">
            <h3>Co-Pilot documentale</h3>
            @if (vm.copilotQuality(); as quality) {
              <mvp-metric-card
                [label]="quality.label"
                [value]="quality.value"
                [unit]="quality.unit"
                [isLoading]="vm.loading()"
                context="campi oltre la soglia di confidenza"
              />
            }
            <button mvpButton variant="secondary" type="button" (click)="vm.navigate('copilot', 'copilot-metrics')">
              Vedi tutte le metriche
            </button>
          </div>
        </div>
      </mvp-section>

      <mvp-section id="overview-activity" title="Attività recenti">
        @if (vm.communications().length) {
          <div class="tableWrapper">
            <table class="table">
              <thead>
                <tr>
                  <th scope="col" data-column="title">Titolo</th>
                  <th scope="col" data-column="status">Stato</th>
                  <th scope="col" data-column="createdAt">Creazione</th>
                </tr>
              </thead>
              <tbody>
                @for (communication of vm.communications(); track communication.id) {
                  <tr>
                    <td data-column="title" data-label="Titolo"><strong>{{ communication.title }}</strong></td>
                    <td data-column="status" data-label="Stato">
                      <mvp-status-badge>{{ communication.status }}</mvp-status-badge>
                    </td>
                    <td data-column="createdAt" data-label="Creazione">
                      {{ formatFallback(communication.createdAt) }}
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        } @else {
          <mvp-empty-state>Le nuove attività compariranno qui.</mvp-empty-state>
        }
      </mvp-section>
    </section>
  `,
  styleUrls: [
    "../../shared/styles/page.css",
    "./overview-page.css",
    "../../shared/components/data-table/data-table.css"
  ]
})
export class OverviewPage {
  protected readonly store = inject(MvpStateStore);
  protected readonly formatFallback = formatFallback;

  protected readonly vm: OverviewPageViewModel;

  constructor() {
    this.vm = new OverviewPageViewModel(this.store, inject(Router), scrollToElement);
  }
}
