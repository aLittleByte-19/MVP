import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { ProgressBarComponent, type ProgressState } from "../../../shared/components/progress-bar/progress-bar";
import type { CommunicationGenerationPhase } from "../assistant.model";

/**
 * Adattatore fra le fasi della pipeline di generazione e la barra condivisa.
 * Le fasi arrivano dagli eventi reali dello stream SSE (vedi AssistantService):
 * accodamento, generazione del testo, generazione della copertina, esito. Il
 * messaggio testuale resta sotto la barra, nel pannello di generazione.
 */
@Component({
  selector: "mvp-generation-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProgressBarComponent],
  template: `
    <mvp-progress-bar [value]="value()" [state]="state()" label="Avanzamento generazione della bozza" />
  `
})
export class GenerationProgressComponent {
  readonly phase = input.required<CommunicationGenerationPhase | "idle" | null>();

  protected readonly value = computed(() => progressValue(this.phase()));
  protected readonly state = computed<ProgressState>(() => {
    const phase = this.phase();

    if (phase === null || phase === "idle") {
      return "idle";
    }

    if (phase === "failed") {
      return "error";
    }

    return phase === "completed" ? "done" : "active";
  });
}

/**
 * Percentuale associata a ciascuna fase. Il testo arriva prima della copertina,
 * quindi "generating-cover" e' gia' a buon punto: la bozza e' leggibile e manca
 * solo l'immagine, che se non arriva non la invalida.
 */
function progressValue(phase: CommunicationGenerationPhase | "idle" | null): number {
  switch (phase) {
    case "queued":
      return 25;
    case "generating-text":
      return 55;
    case "generating-cover":
      return 85;
    case "still_running":
      return 90;
    case "completed":
    case "failed":
      return 100;
    default:
      return 0;
  }
}
