import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import { FileDropzoneComponent } from "../../../shared/components/file-dropzone/file-dropzone";
import { SectionComponent } from "../../../layout/section/section";
import type { DocumentUploadPhase } from "../data/document-workflow.service";
import { UploadProgressComponent } from "./upload-progress";

@Component({
  selector: "poc-document-upload-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FileDropzoneComponent, SectionComponent, UploadProgressComponent],
  template: `
    <poc-section id="copilot-upload" title="Carica nel repository">
      <p class="note">
        Il sistema analizza automaticamente il documento dopo il caricamento e restituisce i campi rilevati
        nella verifica.
      </p>
      <poc-file-dropzone
        [disabled]="isUploading()"
        label="Seleziona o trascina un documento"
        (fileSelected)="upload.emit($event)"
      />
      @if (phase() !== null) {
        <poc-upload-progress [phase]="phase()" />
      }
      <p class="status" aria-live="polite">{{ status() }}</p>
    </poc-section>
  `,
  styleUrl: "./document-upload-panel.css"
})
export class DocumentUploadPanelComponent {
  readonly isUploading = input.required<boolean>();
  readonly status = input.required<string>();
  readonly phase = input.required<DocumentUploadPhase | null>();
  readonly upload = output<File>();
}
