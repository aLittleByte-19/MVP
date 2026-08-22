import { ChangeDetectionStrategy, Component, effect, input, output } from "@angular/core";
import { FormControl, FormGroup, ReactiveFormsModule } from "@angular/forms";
import { FileDropzoneComponent } from "../../../shared/components/file-dropzone/file-dropzone";
import { SectionComponent } from "../../../layout/section/section";
import { DOCUMENT_TYPE_OPTIONS } from "../../../shared/util/document-field-validators";
import type { DocumentUploadPhase, DocumentUploadMetadata } from "../data/document-workflow.service";
import { UploadProgressComponent } from "./upload-progress";

const MONTHS = [
  { value: 1, label: "Gennaio" },
  { value: 2, label: "Febbraio" },
  { value: 3, label: "Marzo" },
  { value: 4, label: "Aprile" },
  { value: 5, label: "Maggio" },
  { value: 6, label: "Giugno" },
  { value: 7, label: "Luglio" },
  { value: 8, label: "Agosto" },
  { value: 9, label: "Settembre" },
  { value: 10, label: "Ottobre" },
  { value: 11, label: "Novembre" },
  { value: 12, label: "Dicembre" }
] as const;

type DocumentTypeOption = (typeof DOCUMENT_TYPE_OPTIONS)[number];

/** Evento emesso quando l'utente seleziona un file da caricare, con eventuali metadati manuali. */
export interface DocumentUploadRequest {
  file: File;
  metadata: DocumentUploadMetadata;
}

@Component({
  selector: "mvp-document-upload-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FileDropzoneComponent, ReactiveFormsModule, SectionComponent, UploadProgressComponent],
  template: `
    <mvp-section id="copilot-upload" title="Carica nel repository">
      <p class="note">
        Il sistema analizza automaticamente il documento dopo il caricamento. Tipologia, mese, anno e azienda
        impostati qui restano autoritativi e non vengono sovrascritti dall'AI.
      </p>

      <div class="metadata" role="group" [formGroup]="metadataForm" aria-label="Metadati documento (opzionali)">
        <fieldset class="types">
          <legend>Tipologia documento</legend>
          <div class="typeButtons" role="group" aria-label="Seleziona tipologia documento">
            @for (option of documentTypes; track option) {
              <button
                type="button"
                class="typeButton"
                [class.active]="metadataForm.controls.documentType.value === option"
                [attr.aria-pressed]="metadataForm.controls.documentType.value === option"
                (click)="selectDocumentType(option)"
              >
                {{ option }}
              </button>
            }
          </div>
        </fieldset>

        <div class="fields">
          <label class="field" for="upload-month">
            <span>Mese di riferimento</span>
            <select id="upload-month" formControlName="month">
              <option value="">Automatico (AI)</option>
              @for (month of months; track month.value) {
                <option [value]="month.value">{{ month.label }}</option>
              }
            </select>
          </label>

          <label class="field" for="upload-year">
            <span>Anno di riferimento</span>
            <input
              id="upload-year"
              type="number"
              formControlName="year"
              min="1900"
              max="2100"
              placeholder="es. 2026"
            />
          </label>

          <label class="field" for="upload-company">
            <span>Azienda</span>
            <input
              id="upload-company"
              type="text"
              formControlName="companyName"
              placeholder="es. Acme Srl"
              maxlength="500"
            />
          </label>
        </div>
      </div>

      <mvp-file-dropzone
        [disabled]="isUploading()"
        label="Seleziona o trascina un documento"
        (fileSelected)="onFileSelected($event)"
      />
      @if (phase() !== null) {
        <mvp-upload-progress [phase]="phase()" />
      }
      <p class="status" aria-live="polite">{{ status() }}</p>
    </mvp-section>
  `,
  styleUrls: ["../../../shared/styles/field.css", "./document-upload-panel.css"]
})
export class DocumentUploadPanelComponent {
  readonly isUploading = input.required<boolean>();
  readonly status = input.required<string>();
  readonly phase = input.required<DocumentUploadPhase | null>();
  readonly upload = output<DocumentUploadRequest>();

  protected readonly documentTypes = DOCUMENT_TYPE_OPTIONS;
  protected readonly months = MONTHS;

  protected readonly metadataForm = new FormGroup({
    documentType: new FormControl<DocumentTypeOption | "">("", { nonNullable: true }),
    month: new FormControl("", { nonNullable: true }),
    year: new FormControl("", { nonNullable: true }),
    companyName: new FormControl("", { nonNullable: true })
  });

  constructor() {
    effect(() => {
      if (this.isUploading()) {
        this.metadataForm.disable({ emitEvent: false });
      } else {
        this.metadataForm.enable({ emitEvent: false });
      }
    });
  }

  protected selectDocumentType(option: DocumentTypeOption): void {
    if (this.metadataForm.disabled) {
      return;
    }

    const current = this.metadataForm.controls.documentType.value;
    this.metadataForm.controls.documentType.setValue(current === option ? "" : option);
  }

  protected onFileSelected(file: File): void {
    this.upload.emit({ file, metadata: this.toMetadata() });
  }

  private toMetadata(): DocumentUploadMetadata {
    const value = this.metadataForm.getRawValue();
    const documentType = value.documentType.trim();
    const companyName = value.companyName.trim();
    const month = Number.parseInt(value.month, 10);
    const year = Number.parseInt(value.year, 10);

    return {
      documentType: documentType === "" ? undefined : (documentType as DocumentTypeOption),
      companyName: companyName === "" ? undefined : companyName,
      month: Number.isInteger(month) && month >= 1 && month <= 12 ? month : undefined,
      year: Number.isInteger(year) && year >= 1900 && year <= 2100 ? year : undefined
    };
  }
}
