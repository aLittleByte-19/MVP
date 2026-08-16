import { type Signal, computed } from "@angular/core";
import type { Router } from "@angular/router";
import type { MvpState } from "../../../api/generated/model";
import type { MvpView } from "../../core/navigation/app-views";
import { MvpStateStore } from "../../core/state/mvp-state.store";

/**
 * ViewModel (Presentation Model, Fowler) della Overview: non tocca il DOM
 * né l'infrastruttura di rendering di Angular (niente `@Component`,
 * `inject()`, decoratori, injection context, `document`/`window` diretti)
 * — le dipendenze arrivano dal costruttore, non da `inject()`, quindi la
 * classe è istanziabile con `new` e testabile senza `TestBed`.
 * `OverviewPage` (la View) resta l'unico punto accoppiato ad Angular e al
 * DOM: si procura le dipendenze con `inject()`, costruisce questa istanza
 * e le passa `scrollToElement` (vedi `shared/util/scroll.ts`) come
 * funzione, cosicché il ViewModel possa *chiedere* uno scroll dopo la
 * navigazione senza *eseguirlo* direttamente.
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
    private readonly router: Router,
    private readonly scrollTo: (elementId: string) => void
  ) {}

  navigate(view: MvpView, targetId: string): void {
    void this.router.navigate([view]).then(() => this.scrollTo(targetId));
  }
}
