import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import type { DocumentUploadPhase } from "../data/document-workflow.service";

/**
 * Barra di avanzamento orizzontale dell'elaborazione documentale. La fase arriva
 * in tempo reale dallo stream SSE (vedi DocumentWorkflowService) e viene mappata
 * su una percentuale di riempimento. Finche' l'elaborazione e' in corso un
 * bagliore scorre da sinistra a destra (reduced-motion lo disattiva, vedi CSS).
 * Il messaggio testuale della fase resta sotto la barra, nel pannello di upload.
 */
@Component({
  selector: "mvp-upload-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="progress"
      role="progressbar"
      aria-label="Avanzamento elaborazione documento"
      aria-valuemin="0"
      aria-valuemax="100"
      [attr.aria-valuenow]="value()"
      [class.isActive]="isActive()"
      [class.isDone]="phase() === 'completed'"
      [class.isError]="phase() === 'failed'"
    >
      <div class="track">
        <div class="fill" [style.width.%]="value()"></div>
      </div>
    </div>
  `,
  styleUrl: "./upload-progress.css"
})
export class UploadProgressComponent {
  readonly phase = input.required<DocumentUploadPhase | null>();
  protected readonly value = computed(() => progressValue(this.phase()));
  protected readonly isActive = computed(() => {
    const phase = this.phase();
    return phase !== null && phase !== "completed" && phase !== "failed";
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
