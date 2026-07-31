import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { ProgressBarComponent, type ProgressState } from "../../../shared/components/progress-bar/progress-bar";
import type { DocumentUploadPhase } from "../data/document-workflow.service";

/**
 * Adattatore fra le fasi della pipeline documentale e la barra condivisa: la
 * fase arriva in tempo reale dallo stream SSE (vedi DocumentWorkflowService) e
 * viene mappata su percentuale e stato. Il messaggio testuale della fase resta
 * sotto la barra, nel pannello di upload.
 */
@Component({
  selector: "mvp-upload-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProgressBarComponent],
  template: `
    <mvp-progress-bar [value]="value()" [state]="state()" label="Avanzamento elaborazione documento" />
  `
})
export class UploadProgressComponent {
  readonly phase = input.required<DocumentUploadPhase | null>();

  protected readonly value = computed(() => progressValue(this.phase()));
  protected readonly state = computed<ProgressState>(() => {
    const phase = this.phase();

    if (phase === null) {
      return "idle";
    }

    if (phase === "failed") {
      return "error";
    }

    return phase === "completed" ? "done" : "active";
  });
}

/** Percentuale di riempimento associata a ciascuna fase della pipeline. */
function progressValue(phase: DocumentUploadPhase | null): number {
  switch (phase) {
    case "uploading":
      return 20;
    case "queued":
      return 40;
    case "processing":
      return 60;
    case "extracting":
      return 85;
    case "completed":
    case "failed":
      return 100;
    default:
      return 0;
  }
}
