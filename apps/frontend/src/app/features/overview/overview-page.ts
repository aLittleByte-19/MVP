import { ChangeDetectionStrategy, Component, inject } from "@angular/core";
import { Router } from "@angular/router";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { ButtonComponent } from "../../shared/components/button/button";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { MetricCardComponent } from "../../shared/components/metric-card/metric-card";
import { MetricsPanelComponent } from "../../shared/components/metrics-panel/metrics-panel";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../layout/section/section";
import { formatFallback } from "../../shared/util/formatters";
import { OverviewPageViewModel } from "./overview-page.view-model";

/**
 * View della Overview (MVVM in senso classico): nessuna logica di business
 * qui, solo collante col template e procacciamento delle dipendenze via
 * `inject()` per costruire {@link OverviewPageViewModel}. Lo stato
 * condiviso non specifico della pagina (`store.error()`, `store.loading()`,
 * `store.state()` per le metriche degli strumenti) resta legato
 * direttamente allo store, come nelle altre pagine.
 */
@Component({
  selector: "mvp-overview-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    MetricCardComponent,
    MetricsPanelComponent,
    SectionComponent,
    StatusBadgeComponent
  ],
  template: `
    <section class="view" aria-label="Overview operativa">
      @if (store.error(); as error) {
        <mvp-error-state [message]="error" />
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

      <mvp-section id="overview-modules" title="Da dove partire">
        <ul class="flowList">
          <li>
            <strong>AI Assistant Generativo</strong>
            <span>Scrivi il contenuto da comunicare, scegli tono e stile, poi revisiona la bozza proposta.</span>
          </li>
          <li>
            <strong>AI Co-Pilot per CdL</strong>
            <span>Carica un PDF e consulta metadati, destinatari e livello di confidenza dopo l'analisi.</span>
          </li>
          <li>
            <strong>Metriche operative</strong>
            <span>Controlla volumi, qualità e stato delle attività direttamente nelle pagine degli strumenti.</span>
          </li>
        </ul>
      </mvp-section>

      <mvp-section id="overview-priorities" title="Priorità essenziali">
        <div class="priorityGrid">
          <mvp-metric-card label="Bozze generate" [value]="vm.generatedDrafts()" />
          <mvp-metric-card label="Documenti da verificare" [value]="vm.documentsToReview()" />
          <mvp-metric-card label="Documenti pronti" [value]="vm.readyDocuments()" />
        </div>
      </mvp-section>

      <mvp-section title="Metriche strumenti">
        <div class="overviewGrid">
          <div class="metricGroup">
            <h3>AI Assistant</h3>
            <mvp-metrics-panel [isLoading]="store.loading()" [metrics]="store.state()?.assistant?.metrics ?? []" />
          </div>
          <div class="metricGroup">
            <h3>Co-Pilot documentale</h3>
            <mvp-metrics-panel [isLoading]="store.loading()" [metrics]="store.state()?.copilot?.metrics ?? []" />
          </div>
        </div>
      </mvp-section>

      <mvp-section title="Attività recenti">
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
  styleUrls: ["./overview-page.css", "../../shared/components/data-table/data-table.css"]
})
export class OverviewPage {
  protected readonly store = inject(MvpStateStore);
  protected readonly formatFallback = formatFallback;

  protected readonly vm: OverviewPageViewModel;

  constructor() {
    this.vm = new OverviewPageViewModel(this.store, inject(Router));
  }
}
