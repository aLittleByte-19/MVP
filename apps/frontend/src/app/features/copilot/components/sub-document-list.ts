import { ChangeDetectionStrategy, Component, computed, effect, inject, input, output, signal } from "@angular/core";
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from "@angular/forms";
import { DomSanitizer, type SafeResourceUrl } from "@angular/platform-browser";
import { LucideCheckCircle2, LucideCopy, LucidePencil, LucideSave, LucideTrash2, LucideX } from "@lucide/angular";
import type { SubDocument, UpdateExtractedDataRequest, UpdateSendMessageRequest } from "../../../../api/generated/model";
import { ButtonComponent } from "../../../shared/components/button/button";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { SectionComponent } from "../../../layout/section/section";
import { capitalizeFirst, formatConfidence, formatDateForDisplay, formatFallback } from "../../../shared/util/formatters";
import { DOCUMENT_TYPE_OPTIONS, codiceFiscaleValidator } from "../../../shared/util/document-field-validators";
import type { DocumentPreviewStatus } from "../data/document-workflow.service";
import { DocumentStatusTimelineComponent } from "./document-status-timeline";
import {
  EXTRACTED_FIELD_KEYS,
  FieldOriginComponent,
  type FieldOrigin,
  originForField
} from "./field-origin/field-origin";

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

interface SendMessageFormState {
  recipient: string;
  subject: string;
  body: string;
}

const emptySendMessageForm: SendMessageFormState = {
  recipient: "",
  subject: "",
  body: ""
};

@Component({
  selector: "mvp-sub-document-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    DocumentStatusTimelineComponent,
    EmptyStateComponent,
    FieldOriginComponent,
    LucideCheckCircle2,
    LucideCopy,
    LucidePencil,
    LucideSave,
    LucideTrash2,
    LucideX,
    ReactiveFormsModule,
    SectionComponent
  ],
  template: `
    @if (documentItem(); as document) {
      <mvp-section id="copilot-document-detail" [title]="document.title || 'Verifica documento'">
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
            <h3 class="eyebrow">Anteprima documento</h3>
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
              <h3 class="eyebrow">Dati estratti dall'OCR</h3>
              <p class="fieldLegend">
                @if (document.reviewStatus !== "manually_validated") {
                  <span class="legendItem"><mvp-field-origin origin="auto" />Confidenza alta</span>
                  <span class="legendItem"><mvp-field-origin origin="review" />Da revisionare</span>
                }
                <span class="legendItem"><mvp-field-origin origin="manual" />Corretto a mano</span>
                <span class="legendItem"><mvp-field-origin origin="locked" />Dato di sistema</span>
              </p>
            </div>
            @if (document.error) {
              <p class="errorNote">{{ document.error }}</p>
            }
            @if (reviewError()) {
              <p class="errorNote">{{ reviewError() }}</p>
            }

            <!-- Non e' un <form>: il pannello non si invia. Per quasi tutto il
                 tempo e' in sola lettura, e un form senza comando di invio e'
                 una promessa che l'elemento non mantiene. Il salvataggio e'
                 un comando esplicito. -->
            <div
              [formGroup]="form"
              class="inspectorForm"
              [class.isEditing]="isEditing()"
              [class.confidenceLow]="document.reviewStatus === 'needs_review'"
              [class.statusAuto]="document.reviewStatus === 'auto_validated'"
              [class.statusManual]="document.reviewStatus === 'manually_validated'"
            >
              <div class="inspectorGrid">
                <label class="field editableField">
                  <span>Nome e cognome</span>
                  <div class="control">
                    @if (fieldOrigin(document, "employeeName"); as origin) {
                      <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'employeeName')" />
                    }
                    <input
                      formControlName="employeeName"
                      [readOnly]="!isEditing()"
                      [attr.aria-readonly]="!isEditing()"
                      [attr.tabindex]="isEditing() ? null : -1"
                    />
                  </div>
                </label>
                <label class="field editableField">
                  <span>Azienda</span>
                  <div class="control">
                    @if (fieldOrigin(document, "companyName"); as origin) {
                      <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'companyName')" />
                    }
                    <input
                      formControlName="companyName"
                      [readOnly]="!isEditing()"
                      [attr.aria-readonly]="!isEditing()"
                      [attr.tabindex]="isEditing() ? null : -1"
                    />
                  </div>
                </label>
                <label class="field lockedField">
                  <span>Nome file</span>
                  <div class="control">
                    <mvp-field-origin origin="locked" />
                    <input [value]="formatFallback(document.file)" readOnly disabled tabindex="-1" />
                  </div>
                </label>
                <label class="field editableField" for="document-date-field">
                  <span>Data documento</span>
                  <div class="control">
                    @if (fieldOrigin(document, "documentDate"); as origin) {
                      <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'documentDate')" />
                    }
                    @if (isEditing()) {
                      <input id="document-date-field" type="date" formControlName="documentDate" />
                    } @else {
                      <input
                        id="document-date-field"
                        [value]="documentDateDisplay(document)"
                        readOnly
                        aria-readonly="true"
                        tabindex="-1"
                      />
                    }
                  </div>
                </label>
                <label class="field lockedField">
                  <span>Numero pagine</span>
                  <div class="control">
                    <mvp-field-origin origin="locked" />
                    <input [value]="formatFallback(document.pages)" readOnly disabled tabindex="-1" />
                  </div>
                </label>
                <label class="field editableField" for="document-type-field">
                  <span>Tipologia documento</span>
                  <div class="control">
                    @if (fieldOrigin(document, "documentType"); as origin) {
                      <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'documentType')" />
                    }
                    @if (isEditing()) {
                      <select id="document-type-field" formControlName="documentType">
                        <option value="">Nessuna tipologia</option>
                        @for (option of documentTypeOptions(); track option) {
                          <option [value]="option">
                            {{ isPredefinedDocumentType(option) ? capitalizeFirst(option) : option }}
                          </option>
                        }
                      </select>
                    } @else {
                      <input
                        id="document-type-field"
                        [value]="formatFallback(document.documentType)"
                        readOnly
                        disabled
                        tabindex="-1"
                      />
                    }
                  </div>
                </label>
                <label class="field lockedField">
                  <span>Confidenza</span>
                  <div class="control">
                    <mvp-field-origin origin="locked" />
                    <input [value]="confidenceDisplay(document)" readOnly disabled tabindex="-1" />
                  </div>
                </label>
                <label class="field lockedField">
                  <span>Stato revisione</span>
                  <div class="control">
                    <mvp-field-origin origin="locked" />
                    <input [value]="document.reviewStatusLabel" readOnly disabled tabindex="-1" />
                  </div>
                </label>
                <label class="field lockedField">
                  <span>Data e ora di caricamento</span>
                  <div class="control">
                    <mvp-field-origin origin="locked" />
                    <input [value]="formatFallback(document.uploadedAt)" readOnly disabled tabindex="-1" />
                  </div>
                </label>
                <label class="field editableField formFull">
                  <span>Descrizione</span>
                  <div class="control">
                    @if (fieldOrigin(document, "description"); as origin) {
                      <mvp-field-origin class="tall" [origin]="origin" />
                    }
                    <textarea
                      rows="3"
                      formControlName="description"
                      [readOnly]="!isEditing()"
                      [attr.aria-readonly]="!isEditing()"
                      [attr.tabindex]="isEditing() ? null : -1"
                    ></textarea>
                  </div>
                </label>
                <!-- Un campo solo: l'email compariva due volte, una in sola
                     lettura con il comando di copia e una modificabile, sullo
                     stesso valore. Il comando resta, accanto al campo che si
                     puo' anche correggere. -->
                <label class="field editableField">
                  <span>Email destinatario</span>
                  <div class="withAction">
                    <div class="control">
                      @if (fieldOrigin(document, "recipientEmail"); as origin) {
                        <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'recipientEmail')" />
                      }
                      <input
                        formControlName="recipientEmail"
                        [readOnly]="!isEditing()"
                        [attr.aria-readonly]="!isEditing()"
                        [attr.tabindex]="isEditing() ? null : -1"
                        [class.invalid]="fieldError('recipientEmail')"
                      />
                    </div>
                    @if (document.recipientEmail && !isEditing()) {
                      <button
                        mvpButton
                        variant="icon"
                        type="button"
                        class="copyButton"
                        aria-label="Copia email destinatario"
                        (click)="copyRecipientEmail(document.recipientEmail)"
                      >
                        <svg lucideCopy aria-hidden="true"></svg>
                      </button>
                    }
                  </div>
                  @if (fieldError("recipientEmail"); as message) {
                    <span class="fieldError">{{ message }}</span>
                  }
                  @if (copiedEmail()) {
                    <small class="copyFeedback">Email copiata negli appunti.</small>
                  }
                </label>

                <label class="field editableField">
                  <span>Codice Fiscale</span>
                  <div class="control">
                    @if (fieldOrigin(document, "fiscalCode"); as origin) {
                      <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'fiscalCode')" />
                    }
                    <input
                      formControlName="fiscalCode"
                      [readOnly]="!isEditing()"
                      [attr.aria-readonly]="!isEditing()"
                      [attr.tabindex]="isEditing() ? null : -1"
                      [class.invalid]="fieldError('fiscalCode')"
                    />
                  </div>
                  @if (fieldError("fiscalCode"); as message) {
                    <span class="fieldError">{{ message }}</span>
                  }
                </label>

                <label class="field editableField">
                  <span>Matricola dipendente</span>
                  <div class="control">
                    @if (fieldOrigin(document, "employeeId"); as origin) {
                      <mvp-field-origin [origin]="origin" [confidence]="fieldConfidence(document, 'employeeId')" />
                    }
                    <input
                      formControlName="employeeId"
                      [readOnly]="!isEditing()"
                      [attr.aria-readonly]="!isEditing()"
                      [attr.tabindex]="isEditing() ? null : -1"
                    />
                  </div>
                </label>
              </div>

              <div class="actionBar">
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
                  <button mvpButton type="button" [disabled]="isSavingReview()" [busy]="isSavingReview()" (click)="saveReview()">
                    <svg lucideSave aria-hidden="true"></svg>
                    Salva
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
                    [disabled]="isSavingReview() || document.reviewStatus === 'manually_validated'"
                    (click)="markReviewed.emit(document.id)"
                  >
                    <svg lucideCheckCircle2 aria-hidden="true"></svg>
                    {{ document.reviewStatus === "manually_validated" ? "Dati confermati" : "Conferma dati correnti" }}
                  </button>
                  <button
                    mvpButton
                    variant="secondary"
                    type="button"
                    [disabled]="!canPrepareMessage(document)"
                    (click)="isSendOpen.set(true)"
                  >
                    Invia
                  </button>
                  @if (!canPrepareMessage(document)) {
                    <span class="actionHint">Conferma i dati per preparare il messaggio.</span>
                  }
                }
              </div>
            </div>

            @if (isSendOpen()) {
              <div
                [formGroup]="sendForm"
                class="sendSection"
                [class.isEditing]="isSendEditing()"
              >
                <div class="inspectorHeading">
                  <h3 class="eyebrow">Messaggio precompilato</h3>
                  <button mvpButton variant="icon" type="button" aria-label="Chiudi" (click)="isSendOpen.set(false)">
                    <svg lucideX aria-hidden="true"></svg>
                  </button>
                </div>
                @if (sendMessageError()) {
                  <p class="errorNote">{{ sendMessageError() }}</p>
                }
                <label class="field editableField">
                  <span>Destinatario</span>
                  <input
                    formControlName="recipient"
                    [readOnly]="!isSendEditing()"
                    [attr.aria-readonly]="!isSendEditing()"
                    [attr.tabindex]="isSendEditing() ? null : -1"
                  />
                </label>
                <label class="field editableField">
                  <span>Oggetto</span>
                  <input
                    formControlName="subject"
                    [readOnly]="!isSendEditing()"
                    [attr.aria-readonly]="!isSendEditing()"
                    [attr.tabindex]="isSendEditing() ? null : -1"
                  />
                </label>
                <label class="field editableField">
                  <span>Testo</span>
                  <textarea
                    rows="6"
                    formControlName="body"
                    [readOnly]="!isSendEditing()"
                    [attr.aria-readonly]="!isSendEditing()"
                    [attr.tabindex]="isSendEditing() ? null : -1"
                  ></textarea>
                </label>
                <div class="previewActions">
                  @if (isSendEditing()) {
                    <button
                      mvpButton
                      variant="secondary"
                      type="button"
                      [disabled]="isSavingSendMessage()"
                      (click)="cancelSendMessageEdit(document)"
                    >
                      <svg lucideX aria-hidden="true"></svg>
                      Annulla
                    </button>
                    <button
                      mvpButton
                      variant="secondary"
                      type="button"
                      [disabled]="isSavingSendMessage()"
                      [busy]="isSavingSendMessage()"
                      (click)="saveSendMessage(document)"
                    >
                      <svg lucideSave aria-hidden="true"></svg>
                      Salva
                    </button>
                  } @else {
                    <button mvpButton variant="secondary" type="button" (click)="isSendEditing.set(true)">
                      <svg lucidePencil aria-hidden="true"></svg>
                      Modifica
                    </button>
                    <a class="previewLink" [href]="document.sendPreviewUrl" target="_blank" rel="noreferrer">
                      Apri anteprima
                    </a>
                    <a class="previewLink downloadLink" [href]="document.sendExportUrl">Scarica PDF</a>
                  }
                </div>
              </div>
            }
          </article>
        </div>
      </mvp-section>
    } @else {
      <mvp-section title="Verifica documento">
        <mvp-empty-state>Seleziona un documento dallo storico per visualizzare il dettaglio.</mvp-empty-state>
      </mvp-section>
    }
  `,
  styleUrls: [
    "../../../shared/styles/field.css",
    "../../../shared/styles/notice.css",
    "../../../shared/styles/link-button.css",
    "./sub-document-list.css"
  ]
})
export class SubDocumentListComponent {
  readonly documentItem = input<SubDocument | null>(null);
  readonly previewStatus = input.required<DocumentPreviewStatus>();
  readonly isDeleting = input.required<boolean>();
  readonly isSavingReview = input.required<boolean>();
  readonly reviewError = input<string | null>(null);
  readonly isSavingSendMessage = input<boolean>(false);
  readonly sendMessageError = input<string | null>(null);
  readonly fieldErrors = input<Record<string, string> | null>(null);
  readonly deleteDocument = output<string>();
  readonly markReviewed = output<string>();
  readonly saveReviewRequested = output<{ documentId: string; payload: UpdateExtractedDataRequest }>();
  readonly saveSendMessageRequested = output<{ documentId: string; payload: UpdateSendMessageRequest }>();

  protected readonly formatFallback = formatFallback;
  protected readonly capitalizeFirst = capitalizeFirst;
  // Il classificatore AI non e' vincolato alle 6 tipologie fisse (UC-43): se il
  // documento ha gia' un valore fuori enum, lo si aggiunge come opzione extra
  // cosi' la select lo mostra selezionato invece di apparire vuota.
  protected readonly documentTypeOptions = computed<string[]>(() => {
    const current = this.documentItem()?.documentType ?? "";
    return current && !this.isPredefinedDocumentType(current)
      ? [current, ...DOCUMENT_TYPE_OPTIONS]
      : [...DOCUMENT_TYPE_OPTIONS];
  });
  protected readonly isEditing = signal(false);
  protected readonly isSendOpen = signal(false);

  /** Il documento che i moduli stanno mostrando, per distinguere un cambio di scheda da un aggiornamento. */
  private shownDocumentId: string | null = null;
  protected readonly isSendEditing = signal(false);
  protected readonly copiedEmail = signal(false);
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
    recipientEmail: new FormControl("", { nonNullable: true, validators: [Validators.email] }),
    fiscalCode: new FormControl("", { nonNullable: true, validators: [codiceFiscaleValidator] }),
    employeeId: new FormControl("", { nonNullable: true })
  });
  protected readonly sendForm = new FormGroup({
    recipient: new FormControl("", { nonNullable: true }),
    subject: new FormControl("", { nonNullable: true }),
    body: new FormControl("", { nonNullable: true })
  });

  private readonly sanitizer = inject(DomSanitizer);

  constructor() {
    // La raggiungibilità dell'anteprima è una fetch, non presentazione: vive
    // in CopilotPageViewModel.loadPreviewStatus() e arriva qui come input
    // (`previewStatus`), cosicché questo componente resti "dumb" — riceve
    // stato, non lo produce interrogando un servizio per conto proprio.
    effect(() => {
      this.resetForm(this.documentItem());
    });

    // Evidenzia il campo segnalato dal backend (UC-55/UC-69) invece di un
    // banner generico: rimuove prima i vecchi errori "backend" residui, poi
    // riapplica quelli correnti (l'oggetto arriva nuovo ad ogni tentativo).
    effect(() => {
      const errors = this.fieldErrors();

      for (const control of Object.values(this.form.controls)) {
        if (control.hasError("backend")) {
          const remainingErrors = { ...control.errors };
          delete remainingErrors["backend"];
          control.setErrors(Object.keys(remainingErrors).length ? remainingErrors : null);
        }
      }

      if (!errors) {
        return;
      }

      for (const [field, message] of Object.entries(errors)) {
        const control = this.form.get(field);

        if (control) {
          control.setErrors({ ...control.errors, backend: message });
          control.markAsTouched();
        }
      }
    });
  }

  /** Messaggio d'errore per il campo, se non valido e gia' toccato dall'utente. */
  protected fieldError(name: "recipientEmail" | "fiscalCode"): string | null {
    const control = this.form.get(name);

    if (!control || !control.touched || control.valid) {
      return null;
    }

    if (typeof control.errors?.["backend"] === "string") {
      return control.errors["backend"];
    }

    if (control.errors?.["email"]) {
      return "Formato e-mail non valido.";
    }

    if (control.errors?.["codiceFiscale"]) {
      return "Il codice fiscale non supera il controllo di validità formale.";
    }

    return null;
  }

  /**
   * Il messaggio si prepara solo su un documento che una persona ha confermato.
   *
   * Bastava la validazione automatica, e su un'estrazione limpida il comando
   * era attivo senza che nessuno avesse letto la scheda. Ma quel messaggio
   * porta il nome di un dipendente su un documento che gli verra' consegnato:
   * la soglia dice che il testo era leggibile, non che il documento sia il suo.
   * Chiude la questione aperta 3 dell'ADR 0012 dalla parte della conferma
   * umana, che e' la sola a costare un secondo e a valere una firma.
   */
  /**
   * Contrassegno del campo estratto, a destra dell'etichetta.
   *
   * L'evidenziazione dei campi passa dal colore — azzurro, verde o ambra a
   * seconda di come il dato e' stato stabilito — e SC 1.4.1 chiede che quella
   * stessa informazione arrivi anche per altra via. Il glifo e' quello che
   * l'etichetta di stato usa gia' per lo stesso tono, cosi' la riga della
   * tabella e la scheda parlano la stessa lingua. Resta `aria-hidden`: chi
   * legge con uno screen reader ha il campo "Stato revisione" e la sola
   * lettura marcata su ogni controllo.
   */
  /**
   * Da dove viene il dato di un singolo campo.
   *
   * Tre casi distinti, che prima collassavano tutti sullo stato del
   * sotto-documento:
   *
   * - il campo e' stato toccato in questa sessione (`dirty`): il valore lo ha
   *   scritto l'operatore, e lo dice la penna anche prima del salvataggio;
   * - il campo e' vuoto: non c'e' alcuna provenienza da dichiarare, e le
   *   scintille su una casella vuota affermavano che l'AI avesse estratto un
   *   nulla;
   * - altrimenti vale lo stato del sotto-documento, che e' quanto il contratto
   *   sa dire (questione aperta 2 dell'ADR 0012: non c'e' una provenienza per
   *   campo persistita).
   */
  protected fieldOrigin(documentItem: SubDocument, controlName: string): FieldOrigin | null {
    const control = this.form.get(controlName);

    if (control?.dirty) {
      return "manual";
    }

    const value = control?.value;

    if (value === null || value === undefined || String(value).trim() === "") {
      return null;
    }

    return originForField(documentItem, EXTRACTED_FIELD_KEYS[controlName]);
  }

  /**
   * Quanto era leggibile il testo da cui viene il campo, per il suggerimento.
   * Null quando il documento e' stato elaborato prima del dettaglio per riga,
   * o quando il valore non e' stato rintracciato fra le righe OCR.
   */
  protected fieldConfidence(documentItem: SubDocument, controlName: string): number | null {
    const keys = EXTRACTED_FIELD_KEYS[controlName];
    const confidences = documentItem.fieldConfidences;

    if (!keys || !confidences) {
      return null;
    }

    const values = keys.map((key) => confidences[key]).filter((value): value is number => typeof value === "number");

    return values.length > 0 ? Math.min(...values) : null;
  }


  protected canPrepareMessage(documentItem: SubDocument): boolean {
    return documentItem.reviewStatus === "manually_validated";
  }

  protected isPredefinedDocumentType(value: string): boolean {
    return (DOCUMENT_TYPE_OPTIONS as readonly string[]).includes(value);
  }

  /**
   * Riporta i due moduli sui dati del documento.
   *
   * Il pannello del messaggio si chiude solo passando a un altro documento:
   * ogni salvataggio fa arrivare la scheda aggiornata e quindi ripassa di qui,
   * e chiudendo sempre il pannello spariva sotto le mani di chi aveva appena
   * confermato il testo.
   */
  protected resetForm(document: SubDocument | null): void {
    const changedDocument = (document?.id ?? null) !== this.shownDocumentId;
    this.shownDocumentId = document?.id ?? null;

    this.form.setValue(toReviewForm(document));
    this.form.markAsUntouched();
    this.isEditing.set(false);
    this.sendForm.setValue(toSendMessageForm(document));
    this.isSendEditing.set(false);

    if (changedDocument) {
      this.isSendOpen.set(false);
    }
  }

  protected cancelSendMessageEdit(document: SubDocument): void {
    this.sendForm.setValue(toSendMessageForm(document));
    this.isSendEditing.set(false);
  }

  protected saveSendMessage(document: SubDocument): void {
    const formValue = this.sendForm.getRawValue();

    this.saveSendMessageRequested.emit({
      documentId: document.id,
      payload: {
        recipient: nullableTrim(formValue.recipient),
        subject: nullableTrim(formValue.subject),
        body: nullableTrim(formValue.body)
      }
    });
  }

  protected confidenceDisplay(document: SubDocument): string {
    return formatConfidence(document.confidence);
  }

  protected documentDateDisplay(document: SubDocument): string {
    return formatDateForDisplay(document.documentDate);
  }

  protected copyRecipientEmail(email: string): void {
    navigator.clipboard
      .writeText(email)
      .then(() => {
        this.copiedEmail.set(true);
        setTimeout(() => this.copiedEmail.set(false), 2000);
      })
      .catch(() => {
        this.copiedEmail.set(false);
      });
  }

  protected saveReview(): void {
    const document = this.documentItem();

    if (!document) {
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saveReviewRequested.emit({
      documentId: document.id,
      payload: this.buildPayload()
    });
  }

  private buildPayload(): UpdateExtractedDataRequest {
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
      markAsValidated: false
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
    companyName: documentItem.companyName ?? "",
    documentDate: documentItem.documentDate ?? "",
    documentType: documentItem.documentType ?? "",
    description: documentItem.description ?? "",
    recipientEmail: documentItem.recipientEmail ?? "",
    fiscalCode: documentItem.fiscalCode ?? "",
    employeeId: documentItem.employeeId ?? ""
  };
}

function toSendMessageForm(documentItem: SubDocument | null): SendMessageFormState {
  if (!documentItem) {
    return emptySendMessageForm;
  }

  return {
    recipient: documentItem.sendRecipient ?? "",
    subject: documentItem.sendSubject ?? "",
    body: documentItem.sendBody ?? ""
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
