import { TestBed } from "@angular/core/testing";
import { of } from "rxjs";
import type { SubDocument } from "../../../../api/generated/model";
import { DocumentWorkflowService } from "../data/document-workflow.service";
import { SubDocumentListComponent } from "./sub-document-list";

/**
 * Spec a se' stante perche' rende il template vero: sub-document-list.spec.ts
 * lo sostituisce con una stringa vuota per provare la sola classe.
 *
 * Difende una scelta che sembra arbitraria e non lo e': l'ancora dello
 * scorrimento sta sulla sezione interna e non sull'host del componente, perche'
 * l'host ha `display: contents` e quindi non genera alcun box. Con l'id li'
 * sopra, `scrollIntoView` non aveva nulla verso cui scorrere e il comando
 * "Consulta" non muoveva la pagina.
 */
describe("ancora dello scorrimento del dettaglio", () => {
  function documento(): SubDocument {
    return {
      id: "sub-1",
      employee: "Mario Rossi",
      companyName: "Acme SpA",
      file: "cedolino.pdf",
      pages: 1,
      reviewStatus: "auto_validated",
      reviewStatusLabel: "Validato automaticamente",
      sendStatus: "pending",
      sendStatusLabel: "Non scaricato",
      previewUrl: "/api/v1/documents/1/preview",
      sendRecipient: "Mario Rossi",
      sendSubject: "Invio documento",
      sendBody: "Testo",
      sendPreviewUrl: "/api/v1/documents/1/send-preview",
      sendExportUrl: "/api/v1/documents/1/send-export",
      previewLines: []
    } as unknown as SubDocument;
  }

  it("sta sulla sezione interna, che genera un box, non sull'host", () => {
    TestBed.configureTestingModule({
      providers: [
        { provide: DocumentWorkflowService, useValue: { previewStatus: () => of("unavailable") } }
      ]
    });

    const fixture = TestBed.createComponent(SubDocumentListComponent);
    fixture.componentRef.setInput("documentItem", documento());
    fixture.componentRef.setInput("isDeleting", false);
    fixture.componentRef.setInput("isSavingReview", false);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    const ancora = host.querySelector("#copilot-document-detail");

    expect(ancora).not.toBeNull();
    expect(ancora?.tagName.toLowerCase()).toBe("mvp-section");
  });
});
