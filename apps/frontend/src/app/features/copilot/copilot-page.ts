import { ChangeDetectionStrategy, Component, computed, inject, signal } from "@angular/core";
import { finalize } from "rxjs";
import type { UpdateExtractedDataRequest } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { getApiErrorMessage } from "../../core/errors/api-error";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { MetricsPanelComponent } from "../../shared/components/metrics-panel/metrics-panel";
import { SectionComponent } from "../../layout/section/section";
import { DocumentWorkflowService, type DocumentUploadPhase } from "./data/document-workflow.service";
import { DocumentListComponent } from "./components/document-list";
import { DocumentUploadPanelComponent } from "./components/document-upload-panel";
import { SubDocumentListComponent } from "./components/sub-document-list";

@Component({
  selector: "mvp-copilot-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    DocumentListComponent,
    DocumentUploadPanelComponent,
    ErrorStateComponent,
    MetricsPanelComponent,
    SectionComponent,
    SubDocumentListComponent
  ],
  template: `
    <section class="view" aria-label="AI Co-Pilot per i CdL">
      @if (store.error(); as error) {
        <mvp-error-state [message]="error" />
      }

      <mvp-document-upload-panel
        [isUploading]="isUploading()"
        [status]="uploadStatus()"
        [phase]="uploadPhase()"
        (upload)="upload($event)"
      />

      <mvp-section id="copilot-documents" title="Storico documenti analizzati">
        <span actions>{{ documents().length }} record</span>
        <mvp-document-list
          [documents]="documents()"
          [selectedDocumentId]="selectedDocumentIdForList()"
          (selectDocument)="selectDocument($event)"
        />
      </mvp-section>

      <mvp-sub-document-list
        [documentItem]="selectedDocument()"
        [isDeleting]="isDeleting()"
        [isSavingReview]="isSavingReview()"
        [reviewError]="reviewError()"
        (deleteDocument)="deleteDocument($event)"
        (markReviewed)="markReviewed($event)"
        (saveReviewRequested)="saveReview($event)"
      />

      <mvp-section id="copilot-metrics" title="Qualita e performance OCR">
        <mvp-metrics-panel [isLoading]="store.loading()" [metrics]="store.copilotMetrics()" />
      </mvp-section>
    </section>
  `,
  styleUrl: "../overview/overview-page.css"
})
export class CopilotPage {
  protected readonly store = inject(MvpStateStore);
  protected readonly documents = this.store.documents;
  protected readonly selectedDocumentId = signal<string | null>(null);
  protected readonly selectedDocument = computed(() => {
    const selectedId = this.selectedDocumentId();
    return this.documents().find((documentItem) => documentItem.id === selectedId) ?? this.documents()[0] ?? null;
  });
  protected readonly selectedDocumentIdForList = computed(() => this.selectedDocument()?.id ?? null);
  protected readonly isUploading = signal(false);
  protected readonly uploadStatus = signal("Nessun caricamento in corso.");
  protected readonly uploadPhase = signal<DocumentUploadPhase | null>(null);
  protected readonly isDeleting = signal(false);
  protected readonly isSavingReview = signal(false);
  protected readonly reviewError = signal<string | null>(null);

  private readonly workflow = inject(DocumentWorkflowService);

  protected selectDocument(documentId: string | null): void {
    this.selectedDocumentId.set(documentId);
  }

  protected upload(file: File): void {
    this.isUploading.set(true);
    this.uploadPhase.set("uploading");
    this.uploadStatus.set("Caricamento documento in corso.");

    this.workflow
      .upload(file)
      .pipe(finalize(() => this.isUploading.set(false)))
      .subscribe({
        next: (progress) => {
          this.uploadStatus.set(progress.status);
          this.uploadPhase.set(progress.phase);

          if (progress.receivedDocumentId) {
            this.selectedDocumentId.set(progress.receivedDocumentId);
          }
        },
        error: (error: unknown) => {
          this.uploadPhase.set("failed");
          this.uploadStatus.set(getApiErrorMessage(error, "Upload non disponibile."));
          this.store.reload();
        }
      });
  }

  protected deleteDocument(documentId: string): void {
    this.isDeleting.set(true);

    this.workflow
      .deleteSubDocument(documentId)
      .pipe(finalize(() => this.isDeleting.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(null),
        error: (error: unknown) => {
          this.reviewError.set(getApiErrorMessage(error, "Eliminazione non disponibile."));
        }
      });
  }

  protected markReviewed(documentId: string): void {
    this.reviewError.set(null);
    this.isSavingReview.set(true);

    this.workflow
      .markReviewed(documentId)
      .pipe(finalize(() => this.isSavingReview.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(documentId),
        error: (error: unknown) => {
          this.reviewError.set(getApiErrorMessage(error, "Validazione non disponibile."));
        }
      });
  }

  protected saveReview(event: { documentId: string; payload: UpdateExtractedDataRequest }): void {
    this.reviewError.set(null);
    this.isSavingReview.set(true);

    this.workflow
      .saveExtractedData(event.documentId, event.payload)
      .pipe(finalize(() => this.isSavingReview.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(event.documentId),
        error: (error: unknown) => {
          this.reviewError.set(getApiErrorMessage(error, "Salvataggio revisione non disponibile."));
        }
      });
  }
}
