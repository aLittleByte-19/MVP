import type { Signal, WritableSignal } from "@angular/core";
import { TestBed } from "@angular/core/testing";
import type { FormGroup } from "@angular/forms";
import type { SafeResourceUrl } from "@angular/platform-browser";
import { Observable, of } from "rxjs";
import type { SubDocument, UpdateExtractedDataRequest, UpdateSendMessageRequest } from "../../../../api/generated/model";
import { DocumentWorkflowService, type DocumentPreviewStatus } from "../data/document-workflow.service";
import { SubDocumentListComponent } from "./sub-document-list";

function subDocument(overrides: Partial<SubDocument> = {}): SubDocument {
  return {
    id: "sub-1",
    employeeFirstName: "Mario",
    employeeLastName: "Rossi",
    employee: "Mario Rossi",
    companyName: "Acme SpA",
    title: "Cedolino marzo",
    file: "cedolino.pdf",
    documentDate: "2026-03-31",
    pages: 2,
    documentType: "cedolino",
    description: "Cedolino mensile",
    confidence: 92,
    reviewStatus: "auto_validated",
    reviewStatusLabel: "Validato automaticamente",
    sendStatus: "pending",
    sendStatusLabel: "Non scaricato",
    previewUrl: "/api/v1/documents/1/preview",
    sendRecipient: "paghe@example.test",
    sendSubject: "Cedolino",
    sendBody: "In allegato il documento.",
    sendPreviewUrl: "/api/v1/documents/1/send-preview",
    sendExportUrl: "/api/v1/documents/1/send-export",
    previewLines: [],
    recipientEmail: "mario.rossi@example.test",
    fiscalCode: "RSSMRA80A01H501U",
    employeeId: "EMP-1",
    ...overrides
  };
}

interface TestableSubDocumentList {
  readonly form: FormGroup;
  readonly sendForm: FormGroup;
  readonly documentTypeOptions: Signal<string[]>;
  readonly isEditing: WritableSignal<boolean>;
  readonly isSendOpen: WritableSignal<boolean>;
  readonly isSendEditing: WritableSignal<boolean>;
  readonly previewStatus: WritableSignal<DocumentPreviewStatus>;
  readonly previewSrc: Signal<SafeResourceUrl | null>;
  fieldError(name: "recipientEmail" | "fiscalCode"): string | null;
  isPredefinedDocumentType(value: string): boolean;
  resetForm(document: SubDocument | null): void;
  cancelSendMessageEdit(document: SubDocument): void;
  saveSendMessage(document: SubDocument): void;
  confidenceDisplay(document: SubDocument): string;
  documentDateDisplay(document: SubDocument): string;
  saveReview(): void;
  canPrepareMessage(documentItem: SubDocument): boolean;
  fieldOrigin(documentItem: SubDocument, controlName: string): "auto" | "manual" | "review" | "locked" | null;
  readonly copiedEmail: Signal<boolean>;
  copyRecipientEmail(email: string): void;
}

describe("SubDocumentListComponent", () => {
  let previewStatus: jest.Mock;

  beforeEach(() => {
    previewStatus = jest.fn(() => of<DocumentPreviewStatus>("available"));
    TestBed.configureTestingModule({
      providers: [{ provide: DocumentWorkflowService, useValue: { previewStatus } }]
    });
    TestBed.overrideComponent(SubDocumentListComponent, { set: { template: "", imports: [] } });
  });

  function render(document: SubDocument | null = null) {
    const fixture = TestBed.createComponent(SubDocumentListComponent);
    fixture.componentRef.setInput("documentItem", document);
    fixture.componentRef.setInput("isDeleting", false);
    fixture.componentRef.setInput("isSavingReview", false);
    fixture.detectChanges();

    return {
      fixture,
      component: fixture.componentInstance as unknown as TestableSubDocumentList
    };
  }

  it("inizializza i form vuoti senza richiedere una preview", () => {
    const { component } = render();

    expect(component.form.getRawValue()).toEqual({
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
    });
    expect(component.sendForm.getRawValue()).toEqual({ recipient: "", subject: "", body: "" });
    expect(component.previewStatus()).toBe("idle");
    expect(component.previewSrc()).toBeNull();
    expect(previewStatus).not.toHaveBeenCalled();
  });

  it("carica dati, preview e tipologia personalizzata del documento", () => {
    const document = subDocument({ documentType: "certificazione speciale" });
    const { component } = render(document);

    expect(component.form.getRawValue()).toMatchObject({
      employeeName: "Mario Rossi",
      companyName: "Acme SpA",
      documentType: "certificazione speciale"
    });
    expect(component.sendForm.getRawValue()).toEqual({
      recipient: "paghe@example.test",
      subject: "Cedolino",
      body: "In allegato il documento."
    });
    expect(component.documentTypeOptions()[0]).toBe("certificazione speciale");
    expect(component.previewSrc()).not.toBeNull();
    expect(component.previewStatus()).toBe("available");
    expect(previewStatus).toHaveBeenCalledWith(document.previewUrl);
  });

  it("mantiene una sola copia delle tipologie predefinite", () => {
    const { component } = render(subDocument({ documentType: "cedolino" }));

    expect(component.isPredefinedDocumentType("cedolino")).toBe(true);
    expect(component.isPredefinedDocumentType("tipo libero")).toBe(false);
    expect(component.documentTypeOptions().filter((value) => value === "cedolino")).toHaveLength(1);
  });

  it("annulla le modifiche e ripristina entrambi i form", () => {
    const document = subDocument();
    const { component } = render(document);
    component.isEditing.set(true);
    component.isSendOpen.set(true);
    component.isSendEditing.set(true);
    component.form.patchValue({ employeeName: "Dato cambiato" });
    component.sendForm.patchValue({ subject: "Oggetto cambiato" });

    component.resetForm(document);

    expect(component.form.get("employeeName")?.value).toBe("Mario Rossi");
    expect(component.sendForm.get("subject")?.value).toBe("Cedolino");
    expect(component.isEditing()).toBe(false);
    expect(component.isSendOpen()).toBe(false);
    expect(component.isSendEditing()).toBe(false);
    expect(component.form.untouched).toBe(true);
  });

  it("annulla soltanto la modifica del messaggio di invio", () => {
    const document = subDocument();
    const { component } = render(document);
    component.isSendEditing.set(true);
    component.sendForm.setValue({ recipient: "altro@example.test", subject: "Altro", body: "Altro" });

    component.cancelSendMessageEdit(document);

    expect(component.sendForm.getRawValue()).toEqual({
      recipient: "paghe@example.test",
      subject: "Cedolino",
      body: "In allegato il documento."
    });
    expect(component.isSendEditing()).toBe(false);
  });

  it("emette il messaggio di invio normalizzando gli spazi e i campi vuoti", () => {
    const document = subDocument();
    const fixture = render(document);
    const emitted: { documentId: string; payload: UpdateSendMessageRequest }[] = [];
    fixture.fixture.componentInstance.saveSendMessageRequested.subscribe((event) => emitted.push(event));
    fixture.component.sendForm.setValue({
      recipient: "  paghe@example.test  ",
      subject: "   ",
      body: "  Testo del messaggio.  "
    });

    fixture.component.saveSendMessage(document);

    expect(emitted).toEqual([
      {
        documentId: "sub-1",
        payload: { recipient: "paghe@example.test", subject: null, body: "Testo del messaggio." }
      }
    ]);
  });

  it("non salva una revisione senza documento o con form non valido", () => {
    const empty = render();
    const emptyEmitted: unknown[] = [];
    empty.fixture.componentInstance.saveReviewRequested.subscribe((event) => emptyEmitted.push(event));
    empty.component.saveReview();
    expect(emptyEmitted).toEqual([]);

    const invalid = render(subDocument());
    const invalidEmitted: unknown[] = [];
    invalid.fixture.componentInstance.saveReviewRequested.subscribe((event) => invalidEmitted.push(event));
    invalid.component.form.patchValue({ recipientEmail: "non-valida" });
    invalid.component.saveReview();

    expect(invalidEmitted).toEqual([]);
    expect(invalid.component.form.get("recipientEmail")?.touched).toBe(true);
  });

  it("salva la revisione preservando i campi atomici quando il nome non cambia", () => {
    const fixture = render(subDocument());
    const emitted: { documentId: string; payload: UpdateExtractedDataRequest }[] = [];
    fixture.fixture.componentInstance.saveReviewRequested.subscribe((event) => emitted.push(event));
    fixture.component.form.patchValue({
      companyName: "  Acme Italia  ",
      description: "   ",
      documentDate: "",
      recipientEmail: "mario.rossi@example.test"
    });

    fixture.component.saveReview();

    expect(emitted).toHaveLength(1);
    expect(emitted[0]).toEqual({
      documentId: "sub-1",
      payload: expect.objectContaining({
        employeeFirstName: "Mario",
        employeeLastName: "Rossi",
        companyName: "Acme Italia",
        description: null,
        documentDate: null,
        markAsValidated: false
      })
    });
  });

  it.each([
    ["Mario Luigi Bianchi", "Mario Luigi", "Bianchi"],
    ["Madonna", "Madonna", null],
    ["   ", null, null]
  ])("suddivide il nome modificato %s", (employeeName, firstName, lastName) => {
    const fixture = render(subDocument());
    const emitted: UpdateExtractedDataRequest[] = [];
    fixture.fixture.componentInstance.saveReviewRequested.subscribe((event) => emitted.push(event.payload));
    fixture.component.form.patchValue({ employeeName });

    fixture.component.saveReview();

    expect(emitted[0].employeeFirstName).toBe(firstName);
    expect(emitted[0].employeeLastName).toBe(lastName);
  });

  it("espone messaggi specifici per validazione locale e backend", () => {
    const fixture = render(subDocument());
    const email = fixture.component.form.get("recipientEmail");
    const fiscalCode = fixture.component.form.get("fiscalCode");

    expect(fixture.component.fieldError("recipientEmail")).toBeNull();
    email?.setValue("non-valida");
    email?.markAsTouched();
    fiscalCode?.setValue("INVALIDO");
    fiscalCode?.markAsTouched();

    expect(fixture.component.fieldError("recipientEmail")).toBe("Formato e-mail non valido.");
    expect(fixture.component.fieldError("fiscalCode")).toBe(
      "Il codice fiscale non supera il controllo di validità formale."
    );

    fixture.fixture.componentRef.setInput("fieldErrors", { recipientEmail: "Indirizzo già utilizzato." });
    fixture.fixture.detectChanges();
    expect(fixture.component.fieldError("recipientEmail")).toBe("Indirizzo già utilizzato.");

    fixture.fixture.componentRef.setInput("fieldErrors", null);
    fixture.fixture.detectChanges();
    expect(email?.hasError("backend")).toBe(false);
  });

  it("formatta confidenza e data con fallback leggibili", () => {
    const { component } = render();

    expect(component.confidenceDisplay(subDocument({ confidence: 0 }))).toBe("0%");
    expect(component.confidenceDisplay(subDocument({ confidence: null }))).toBe("Da verificare");
    expect(component.documentDateDisplay(subDocument({ documentDate: "2026-03-31" }))).toBe("31/03/2026");
    expect(component.documentDateDisplay(subDocument({ documentDate: null }))).toBe("Non disponibile");
  });

  it("copia l'email destinatario negli appunti e mostra il feedback per 2 secondi", async () => {
    jest.useFakeTimers();
    const writeText = jest.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const { component } = render();

    component.copyRecipientEmail("mario.rossi@example.test");
    await Promise.resolve();

    expect(writeText).toHaveBeenCalledWith("mario.rossi@example.test");
    expect(component.copiedEmail()).toBe(true);

    jest.advanceTimersByTime(2000);
    expect(component.copiedEmail()).toBe(false);

    jest.useRealTimers();
  });

  it("non mostra il feedback di copia se la scrittura negli appunti fallisce", async () => {
    const writeText = jest.fn().mockRejectedValue(new Error("clipboard non disponibile"));
    Object.assign(navigator, { clipboard: { writeText } });
    const { component } = render();

    component.copyRecipientEmail("mario.rossi@example.test");
    await Promise.resolve();
    await Promise.resolve();

    expect(component.copiedEmail()).toBe(false);
  });

  it("non lascia preparare il messaggio finche' i dati non sono stabiliti come corretti", () => {
    // Prima il comando non aveva alcuna condizione: restava attivo anche su un
    // documento in quarantena, cioe' su dati che il sistema stesso dichiara
    // inaffidabili.
    const { component } = render(subDocument());

    for (const reviewStatus of ["needs_review", "quarantined"] as const) {
      expect(component.canPrepareMessage(subDocument({ reviewStatus }))).toBe(false);
    }

    for (const reviewStatus of ["auto_validated", "manually_validated"] as const) {
      expect(component.canPrepareMessage(subDocument({ reviewStatus }))).toBe(true);
    }
  });

  it("annulla la sottoscrizione alla preview quando il componente viene distrutto", () => {
    const teardown = jest.fn();
    previewStatus.mockReturnValue(
      new Observable<DocumentPreviewStatus>((subscriber) => {
        subscriber.next("loading");
        return teardown;
      })
    );
    const { fixture, component } = render(subDocument());

    expect(component.previewStatus()).toBe("loading");
    fixture.destroy();

    expect(teardown).toHaveBeenCalledTimes(1);
  });

  /**
   * Il `beforeEach` svuota il template per provare la classe senza montare
   * l'intero pannello; la legenda pero' vive solo li', quindi questi due casi
   * ripartono da un TestBed senza quella sostituzione.
   */
  function renderLegend(document: SubDocument): string {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: DocumentWorkflowService, useValue: { previewStatus } }]
    });

    const fixture = TestBed.createComponent(SubDocumentListComponent);
    fixture.componentRef.setInput("documentItem", document);
    fixture.componentRef.setInput("isDeleting", false);
    fixture.componentRef.setInput("isSavingReview", false);
    fixture.detectChanges();

    return fixture.nativeElement.querySelector(".fieldLegend").textContent as string;
  }

  it("elenca in cima tutti i segni che possono comparire sulle caselle", () => {
    // SC 1.4.1: teal, verde e ambra dicono la provenienza, e la stessa cosa
    // deve arrivare a chi quei colori non li distingue — qui con la forma
    // dell'icona e con il testo che l'accompagna. La provenienza e' per campo,
    // quindi nello stesso documento convivono il segno della buona confidenza e
    // quello del dato da rivedere: la legenda li nomina entrambi invece di
    // dichiarare lo stato complessivo della scheda.
    const legend = renderLegend(subDocument({ reviewStatus: "needs_review" }));

    expect(legend).toContain("Confidenza alta");
    expect(legend).toContain("Da revisionare");
    expect(legend).toContain("Corretto a mano");
    expect(legend).toContain("Dato di sistema");
  });

  it("sulla scheda confermata a mano tace i segni dell'AI, che non compaiono", () => {
    const legend = renderLegend(subDocument({ reviewStatus: "manually_validated" }));

    expect(legend).not.toContain("Confidenza alta");
    expect(legend).not.toContain("Da revisionare");
    expect(legend).toContain("Corretto a mano");
  });

  it("non dichiara alcuna provenienza su un campo vuoto", () => {
    // Le scintille su una casella vuota affermavano che l'AI avesse estratto
    // un nulla: i tre identificativi sono spesso assenti dal documento.
    const document = subDocument({ reviewStatus: "auto_validated", fiscalCode: null, employeeId: null });
    const { component } = render(document);

    expect(component.fieldOrigin(document, "employeeName")).toBe("auto");
    expect(component.fieldOrigin(document, "fiscalCode")).toBeNull();
    expect(component.fieldOrigin(document, "employeeId")).toBeNull();
  });

  it("marca come manuale il campo appena corretto, prima ancora del salvataggio", () => {
    const document = subDocument({ reviewStatus: "auto_validated" });
    const { component } = render(document);

    component.form.get("companyName")?.setValue("Acme Srl corretta");
    component.form.get("companyName")?.markAsDirty();

    expect(component.fieldOrigin(document, "companyName")).toBe("manual");
    // Gli altri campi non seguono: la correzione riguarda quello toccato.
    expect(component.fieldOrigin(document, "employeeName")).toBe("auto");
  });
});
