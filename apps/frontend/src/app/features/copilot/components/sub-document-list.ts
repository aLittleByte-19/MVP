import { ChangeDetectionStrategy, Component, computed, effect, inject, input, output, signal } from "@angular/core";
import { FormControl, FormGroup, ReactiveFormsModule } from "@angular/forms";
import { DomSanitizer, type SafeResourceUrl } from "@angular/platform-browser";
import { LucideCheckCircle2, LucidePencil, LucideSave, LucideTrash2, LucideX } from "@lucide/angular";
import type { SubDocument, UpdateExtractedDataRequest } from "../../../../api/generated/model";
import { ButtonComponent } from "../../../shared/components/button/button";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { SectionComponent } from "../../../layout/section/section";
import { formatDateForDisplay, formatFallback } from "../../../shared/util/formatters";
import { DocumentWorkflowService, type DocumentPreviewStatus } from "../data/document-workflow.service";
import { DocumentStatusTimelineComponent } from "./document-status-timeline";

interface ReviewFormState {
  employeeName: string;
  employeeFirstName: string;
  employeeLastName: string;
  companyName: string;
  documentDate: string;
  documentType: string;
  description: string;
  recipientEmail: string;
  fiscalCode: string;
  employeeId: string;
}

const emptyReviewForm: ReviewFormState = {
  employeeName: "",
  employeeFirstName: "",
  employeeLastName: "",
  companyName: "",
  documentDate: "",
  documentType: "",
  description: "",
  recipientEmail: "",
  fiscalCode: "",
  employeeId: ""
};

@Component({
  selector: "mvp-sub-document-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    DocumentStatusTimelineComponent,
    EmptyStateComponent,
    LucideCheckCircle2,
    LucidePencil,
    LucideSave,
    LucideTrash2,
    LucideX,
    ReactiveFormsModule,
    SectionComponent
  ],
  template: `
    @if (documentItem(); as document) {
      <mvp-section [title]="document.title || 'Verifica documento'">
        <button
          actions
          mvpButton
          variant="icon"
          type="button"
          aria-label="Elimina documento"
          [disabled]="isDeleting()"
          (click)="deleteDocument.emit(document.id)"
        >
          <svg lucideTrash2 aria-hidden="true"></svg>
        </button>

        <mvp-document-status-timeline [documentItem]="document" />

        <div class="detailGrid">
          <article class="preview">
            <p class="eyebrow">Anteprima documento</p>
            <div class="previewFrame">
              <strong>{{ formatFallback(document.file, "Documento") }}</strong>
              @if (previewStatus() === "available" && previewSrc(); as src) {
                <iframe
                  class="previewDocument"
                  [src]="src"
                  [title]="'Anteprima di ' + formatFallback(document.file, 'documento')"
                ></iframe>
                <a class="previewLink" [href]="document.previewUrl" target="_blank" rel="noreferrer">
                  Apri in una nuova scheda
                </a>
              } @else if (previewStatus() === "loading") {
                <span>Caricamento anteprima in corso.</span>
              } @else if (previewStatus() === "unreachable") {
                <span>Storage documento non raggiungibile.</span>
              } @else if (previewStatus() === "unavailable") {
                <span>Anteprima non disponibile.</span>
              } @else {
                <span>Anteprima non ancora disponibile per questo documento.</span>
              }
            </div>
            @if (document.previewLines.length) {
              <ul class="previewLines">
                @for (line of document.previewLines; track line) {
                  <li>{{ line }}</li>
                }
              </ul>
            }
          </article>

          <article class="extracted">
            <div class="inspectorHeading">
              <p class="eyebrow">Dati estratti dall'OCR</p>
              <div class="fieldLegend" aria-label="Legenda campi">
                <span><i class="editableDot"></i>Modificabile</span>
                <span><i class="lockedDot"></i>Sola lettura</span>
              </div>
            </div>
            @if (document.error) {
              <p class="errorNote">{{ document.error }}</p>
            }
            @if (reviewError()) {
              <p class="errorNote">{{ reviewError() }}</p>
            }

            <form
              [formGroup]="form"
              class="inspectorForm"
              [class.isEditing]="isEditing()"
              [class.confidenceLow]="document.confidence !== null && document.confidence !== undefined && document.confidence < 80"
              [class.statusAuto]="document.reviewStatus === 'auto_validated'"
              [class.statusManual]="document.reviewStatus === 'manually_validated'"
              (ngSubmit)="saveReview(false)"
            >
              <div class="inspectorGrid">
                <label class="field editableField">
                  <span>Nome e cognome</span>
                  <input formControlName="employeeName" [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" />
                </label>
                <label class="field editableField">
                  <span>Azienda</span>
                  <input formControlName="companyName" [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" />
                </label>
                <label class="field lockedField">
                  <span>Nome file</span>
                  <input [value]="formatFallback(document.file)" readOnly disabled tabindex="-1" />
                </label>
                <label class="field editableField" for="document-date-field">
                  <span>Data documento</span>
                  @if (isEditing()) {
                    <input id="document-date-field" type="date" formControlName="documentDate" />
                  } @else {
                    <input id="document-date-field" [value]="documentDateDisplay(document)" readOnly aria-readonly="true" />
                  }
                </label>
                <label class="field lockedField">
                  <span>Numero pagine</span>
                  <input [value]="formatFallback(document.pages)" readOnly disabled tabindex="-1" />
                </label>
                <label class="field editableField">
                  <span>Tipologia documento</span>
                  <input formControlName="documentType" [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" />
                </label>
                <label class="field lockedField">
                  <span>Confidenza</span>
                  <input [value]="confidenceDisplay(document)" readOnly disabled tabindex="-1" />
                </label>
                <label class="field lockedField">
                  <span>Stato revisione</span>
                  <input [value]="document.reviewStatusLabel" readOnly disabled tabindex="-1" />
                </label>
                <label class="field editableField formFull">
                  <span>Descrizione</span>
                  <textarea
                    rows="3"
                    formControlName="description"
                    [readOnly]="!isEditing()"
                    [attr.aria-readonly]="!isEditing()"
                  ></textarea>
                </label>
                <label class="field editableField">
                  <span>Email destinatario</span>
                  <input formControlName="recipientEmail" [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" />
                </label>

                <label class="field editableField">
                  <span>Codice Fiscale</span>
                  <input formControlName="fiscalCode" [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" />
                </label>

                <label class="field editableField">
                  <span>Matricola dipendente</span>
                  <input formControlName="employeeId" [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" />
                </label>
              </div>

              <div class="reviewActions">
                @if (isEditing()) {
                  <button
                    mvpButton
                    variant="secondary"
                    type="button"
                    [disabled]="isSavingReview()"
                    (click)="resetForm(document)"
                  >
                    <svg lucideX aria-hidden="true"></svg>
                    Annulla
                  </button>
                  <button mvpButton variant="secondary" type="submit" [disabled]="isSavingReview()">
                    <svg lucideSave aria-hidden="true"></svg>
                    Salva correzioni
                  </button>
                  <button mvpButton type="button" [disabled]="isSavingReview()" (click)="saveReview(true)">
                    <svg lucideCheckCircle2 aria-hidden="true"></svg>
                    Salva e valida
                  </button>
                } @else {
                  <button
                    mvpButton
                    variant="secondary"
                    type="button"
                    [disabled]="isSavingReview()"
                    (click)="isEditing.set(true)"
                  >
                    <svg lucidePencil aria-hidden="true"></svg>
                    Modifica
                  </button>
                  <button
                    mvpButton
                    type="button"
                    [disabled]="isSavingReview()"
                    (click)="markReviewed.emit(document.id)"
                  >
                    <svg lucideCheckCircle2 aria-hidden="true"></svg>
                    Conferma dati correnti
                  </button>
                }
              </div>
            </form>
          </article>
        </div>
      </mvp-section>
    } @else {
      <mvp-section title="Verifica documento">
        <mvp-empty-state>Seleziona un documento dallo storico per visualizzare il dettaglio.</mvp-empty-state>
      </mvp-section>
    }
  `,
  styleUrl: "./sub-document-list.css"
})
export class SubDocumentListComponent {
  readonly documentItem = input<SubDocument | null>(null);
  readonly isDeleting = input.required<boolean>();
  readonly isSavingReview = input.required<boolean>();
  readonly reviewError = input<string | null>(null);
  readonly deleteDocument = output<string>();
  readonly markReviewed = output<string>();
  readonly saveReviewRequested = output<{ documentId: string; payload: UpdateExtractedDataRequest }>();

  protected readonly formatFallback = formatFallback;
  protected readonly isEditing = signal(false);
  protected readonly previewStatus = signal<DocumentPreviewStatus>("idle");
  // L'URL dell'anteprima e' una risorsa same-origin generata dal backend; va
  // marcato come SafeResourceUrl, altrimenti Angular blocca il binding su
  // <iframe [src]> ("unsafe value in a resource URL context") e l'anteprima
  // resta vuota.
  protected readonly previewSrc = computed<SafeResourceUrl | null>(() => {
    const url = this.documentItem()?.previewUrl;
    return url ? this.sanitizer.bypassSecurityTrustResourceUrl(url) : null;
  });
  protected readonly form = new FormGroup({
    employeeName: new FormControl("", { nonNullable: true }),
    employeeFirstName: new FormControl("", { nonNullable: true }),
    employeeLastName: new FormControl("", { nonNullable: true }),
    companyName: new FormControl("", { nonNullable: true }),
    documentDate: new FormControl("", { nonNullable: true }),
    documentType: new FormControl("", { nonNullable: true }),
    description: new FormControl("", { nonNullable: true }),
    recipientEmail: new FormControl("", { nonNullable: true }),
    fiscalCode: new FormControl("", { nonNullable: true }),
    employeeId: new FormControl("", { nonNullable: true })
  });

  private readonly workflow = inject(DocumentWorkflowService);
  private readonly sanitizer = inject(DomSanitizer);

  constructor() {
    effect((onCleanup) => {
      const document = this.documentItem();
      this.resetForm(document);

      if (!document?.previewUrl) {
        this.previewStatus.set("idle");
        return;
      }

      const subscription = this.workflow
        .previewStatus(document.previewUrl)
        .subscribe((status) => this.previewStatus.set(status));

      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected resetForm(document: SubDocument | null): void {
    this.form.setValue(toReviewForm(document));
    this.isEditing.set(false);
  }

  protected confidenceDisplay(document: SubDocument): string {
    return document.confidence != null ? `${document.confidence}%` : "Da verificare";
  }

  protected documentDateDisplay(document: SubDocument): string {
    return formatDateForDisplay(document.documentDate ?? document.date);
  }

  protected saveReview(markAsValidated: boolean): void {
    const document = this.documentItem();

    if (!document) {
      return;
    }

    this.saveReviewRequested.emit({
      documentId: document.id,
      payload: this.buildPayload(markAsValidated)
    });
  }

  private buildPayload(markAsValidated: boolean): UpdateExtractedDataRequest {
    const formValue = this.form.getRawValue();
    const employee = splitEmployeeName(formValue);

    return {
      ...employee,
      companyName: nullableTrim(formValue.companyName),
      documentDate: formValue.documentDate || null,
      documentType: nullableTrim(formValue.documentType),
      description: nullableTrim(formValue.description),
      recipientEmail: nullableTrim(formValue.recipientEmail),
      fiscalCode: nullableTrim(formValue.fiscalCode),
      employeeId: nullableTrim(formValue.employeeId),
      markAsValidated
    };
  }
}

function compactEmployeeName(documentItem: SubDocument): string {
  const fromAtomicFields = [documentItem.employeeFirstName, documentItem.employeeLastName]
    .filter(Boolean)
    .join(" ")
    .trim();

  return documentItem.employee ?? fromAtomicFields;
}

function toReviewForm(documentItem: SubDocument | null): ReviewFormState {
  if (!documentItem) {
    return emptyReviewForm;
  }

  return {
    employeeName: compactEmployeeName(documentItem),
    employeeFirstName: documentItem.employeeFirstName ?? "",
    employeeLastName: documentItem.employeeLastName ?? "",
    companyName: documentItem.companyName ?? documentItem.company ?? "",
    documentDate: documentItem.documentDate ?? "",
    documentType: documentItem.documentType ?? documentItem.type ?? "",
    description: documentItem.description ?? "",
    recipientEmail: documentItem.recipientEmail ?? documentItem.email ?? "",
    fiscalCode: documentItem.fiscalCode ?? "",
    employeeId: documentItem.employeeId ?? documentItem.matricola ?? ""
  };
}

function nullableTrim(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function splitEmployeeName(
  formState: ReviewFormState
): Pick<UpdateExtractedDataRequest, "employeeFirstName" | "employeeLastName"> {
  const employeeName = formState.employeeName.trim().replace(/\s+/g, " ");
  const previousName = [formState.employeeFirstName, formState.employeeLastName]
    .filter(Boolean)
    .join(" ")
    .trim()
    .replace(/\s+/g, " ");

  if (employeeName === "") {
    return { employeeFirstName: null, employeeLastName: null };
  }

  if (employeeName === previousName) {
    return {
      employeeFirstName: nullableTrim(formState.employeeFirstName),
      employeeLastName: nullableTrim(formState.employeeLastName)
    };
  }

  const parts = employeeName.split(" ");

  if (parts.length === 1) {
    return { employeeFirstName: parts[0], employeeLastName: null };
  }

  return {
    employeeFirstName: parts.slice(0, -1).join(" "),
    employeeLastName: parts[parts.length - 1] ?? null
  };
}
