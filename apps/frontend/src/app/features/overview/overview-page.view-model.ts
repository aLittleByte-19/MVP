import { type Signal, computed } from "@angular/core";
import type { Router } from "@angular/router";
import type { MvpState } from "../../../api/generated/model";
import type { MvpView } from "../../core/navigation/app-views";
import { MvpStateStore } from "../../core/state/mvp-state.store";

/**
 * ViewModel puro della Overview (MVVM in senso classico): nessun
 * riferimento ad Angular (niente `inject()`, decoratori, injection
 * context) — le dipendenze arrivano dal costruttore, non da `inject()`,
 * quindi la classe è istanziabile con `new` e testabile senza `TestBed`.
 * `OverviewPage` (la View) resta l'unico punto accoppiato ad Angular: si
 * procura le dipendenze con `inject()` e costruisce questa istanza.
 */
export class OverviewPageViewModel {
  readonly communications: Signal<MvpState["assistant"]["history"]> = computed(() => this.store.history());
  // Conteggi sull'intero tenant, non sulle finestre di elenco: `history` si
  // ferma a 10 e `documents` a 40, quindi ricalcolarli qui darebbe numeri
  // silenziosamente sbagliati appena i dati superano quelle soglie.
  readonly generatedDrafts: Signal<number> = computed(() => this.store.metric("assistant.drafts"));
  readonly documentsToReview: Signal<number> = computed(() => this.store.metric("copilot.needs_review"));
  readonly readyDocuments: Signal<number> = computed(() => this.store.metric("copilot.validated"));

  constructor(
    private readonly store: MvpStateStore,
    private readonly router: Router
  ) {}

  navigate(view: MvpView, targetId: string): void {
    void this.router.navigate([view]).then(() => {
      window.requestAnimationFrame(() => {
        document.getElementById(targetId)?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    });
  }
}
