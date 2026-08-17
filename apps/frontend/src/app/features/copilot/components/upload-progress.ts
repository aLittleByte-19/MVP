import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import {
  type ProgressStage,
  StageProgressComponent
} from "../../../shared/components/stage-progress/stage-progress";
import type { DocumentUploadPhase } from "../data/document-workflow.service";

/**
 * Tappe della pipeline documentale, nell'ordine in cui l'elaborazione le
 * attraversa. Sono gli stati reali emessi dallo stream SSE (vedi
 * DocumentWorkflowService), non una scala inventata: la barra precedente
 * mappava le fasi su percentuali fisse (20, 40, 60, 85, 100) che non
 * misuravano alcun avanzamento effettivo.
 */
const STAGES: readonly ProgressStage[] = [
  { id: "uploading", label: "Caricamento" },
  { id: "queued", label: "In coda" },
  { id: "processing", label: "Analisi OCR" },
  { id: "extracting", label: "Estrazione campi" },
  { id: "completed", label: "Completato" }
];

/**
 * Adattatore fra le fasi della pipeline documentale e l'avanzamento condiviso.
 * Il messaggio testuale della fase resta sotto, nel pannello di upload.
 */
@Component({
  selector: "mvp-upload-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [StageProgressComponent],
  template: `
    <mvp-stage-progress
      [stages]="stages"
      [currentId]="currentId()"
      [failed]="failed()"
      [slow]="slow()"
      label="Avanzamento elaborazione documento"
    />
  `
})
export class UploadProgressComponent {
  readonly phase = input.required<DocumentUploadPhase | null>();

  protected readonly stages = STAGES;

  /**
   * `still_running` e `failed` non sono tappe: la prima dice che la pipeline è
   * ferma sull'estrazione da più tempo del previsto, la seconda che si è
   * interrotta. Entrambe restano sulla tappa raggiunta.
   */
  protected readonly currentId = computed<string | null>(() => {
    const phase = this.phase();

    if (phase === null) {
      return null;
    }

    if (phase === "still_running" || phase === "failed") {
      return "extracting";
    }

    return phase;
  });

  protected readonly failed = computed(() => this.phase() === "failed");
  protected readonly slow = computed(() => this.phase() === "still_running");
}
