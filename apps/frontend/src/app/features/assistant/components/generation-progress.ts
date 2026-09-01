import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import {
  type ProgressStage,
  StageProgressComponent
} from "../../../shared/components/stage-progress/stage-progress";
import type { CommunicationGenerationPhase } from "../assistant.model";

/** Tappe della pipeline di generazione, nell'ordine reale in cui lo stream SSE le emette. */
const STAGES: readonly ProgressStage[] = [
  { id: "queued", label: "In coda" },
  { id: "generating-text", label: "Testo" },
  { id: "generating-cover", label: "Copertina" },
  { id: "completed", label: "Bozza pronta" }
];

/**
 * Adattatore fra le fasi della pipeline di generazione e l'avanzamento
 * condiviso. Il messaggio testuale resta sotto, nel pannello di generazione.
 */
@Component({
  selector: "mvp-generation-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [StageProgressComponent],
  template: `
    <mvp-stage-progress
      [stages]="stages"
      [currentId]="currentId()"
      [failed]="failed()"
      [slow]="slow()"
      label="Avanzamento generazione della bozza"
    />
  `
})
export class GenerationProgressComponent {
  readonly phase = input.required<CommunicationGenerationPhase | "idle" | null>();

  protected readonly stages = STAGES;

  protected readonly currentId = computed<string | null>(() => {
    const phase = this.phase();

    if (phase === null || phase === "idle") {
      return null;
    }

    // `still_running` e `failed` non sono tappe: restano sulla copertina, che
    // è l'ultimo passo prima della chiusura.
    if (phase === "still_running" || phase === "failed") {
      return "generating-cover";
    }

    return phase;
  });

  protected readonly failed = computed(() => this.phase() === "failed");
  protected readonly slow = computed(() => this.phase() === "still_running");
}
