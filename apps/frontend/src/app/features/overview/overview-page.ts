import { ChangeDetectionStrategy, Component, computed, inject } from "@angular/core";
import { Router } from "@angular/router";
import type { PocView } from "../../core/navigation/app-views";
import { PocStateStore } from "../../core/state/poc-state.store";
import { ButtonComponent } from "../../shared/components/button/button";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { MetricCardComponent } from "../../shared/components/metric-card/metric-card";
import { MetricsPanelComponent } from "../../shared/components/metrics-panel/metrics-panel";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../layout/section/section";
import { formatFallback } from "../../shared/util/formatters";

@Component({
  selector: "poc-overview-page",
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
        <poc-error-state [message]="error" />
      }

      <poc-section class="hero">
        <p class="eyebrow">Console operativa</p>
        <h2>Gestione assistita di comunicazioni interne e documenti del personale.</h2>
        <p>
          NEXUM supporta redattori HR e operatori CdL nelle attivita quotidiane: preparazione dei contenuti,
          classificazione documentale, verifica degli esiti e tracciamento delle consegne.
        </p>
        <div class="buttonRow">
          <button pocButton type="button" (click)="navigate('assistant', 'assistant-compose')">Crea contenuto</button>
          <button pocButton variant="secondary" type="button" (click)="navigate('copilot', 'copilot-upload')">
            Carica documenti
          </button>
        </div>
      </poc-section>

      <poc-section id="overview-modules" title="Da dove partire">
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
            <span>Controlla volumi, qualita e stato delle attivita direttamente nelle pagine degli strumenti.</span>
          </li>
        </ul>
      </poc-section>

      <poc-section id="overview-priorities" title="Priorita essenziali">
        <div class="priorityGrid">
          <poc-metric-card label="Bozze generate" [value]="communications().length" />
          <poc-metric-card label="Documenti da verificare" [value]="documentsToReview()" />
          <poc-metric-card label="Documenti pronti" [value]="readyDocuments()" />
        </div>
      </poc-section>

      <poc-section title="Metriche strumenti">
        <div class="overviewGrid">
          <div class="metricGroup">
            <h3>AI Assistant</h3>
            <poc-metrics-panel [isLoading]="store.loading()" [metrics]="store.state()?.assistant?.metrics ?? []" />
          </div>
          <div class="metricGroup">
            <h3>Co-Pilot documentale</h3>
            <poc-metrics-panel [isLoading]="store.loading()" [metrics]="store.state()?.copilot?.metrics ?? []" />
          </div>
        </div>
      </poc-section>

      <poc-section title="Attivita recenti">
        @if (communications().length) {
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
                @for (communication of communications(); track communication.id) {
                  <tr>
                    <td data-column="title" data-label="Titolo"><strong>{{ communication.title }}</strong></td>
                    <td data-column="status" data-label="Stato">
                      <poc-status-badge>{{ communication.status }}</poc-status-badge>
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
          <poc-empty-state>Le nuove attivita compariranno qui.</poc-empty-state>
        }
      </poc-section>
    </section>
  `,
  styleUrls: ["./overview-page.css", "../../shared/components/data-table/data-table.css"]
})
export class OverviewPage {
  protected readonly store = inject(PocStateStore);
  protected readonly communications = this.store.history;
  protected readonly documents = this.store.documents;
  protected readonly documentsToReview = computed(
    () => this.documents().filter((documentItem) => documentItem.error).length
  );
  protected readonly readyDocuments = computed(
    () => this.documents().filter((documentItem) => !documentItem.error).length
  );
  protected readonly formatFallback = formatFallback;

  private readonly router = inject(Router);

  protected navigate(view: PocView, targetId: string): void {
    void this.router.navigate([view]).then(() => {
      window.requestAnimationFrame(() => {
        document.getElementById(targetId)?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    });
  }
}
