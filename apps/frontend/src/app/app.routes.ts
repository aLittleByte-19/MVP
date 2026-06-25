import type { Routes } from "@angular/router";
import type { PocView } from "./core/navigation/app-views";

/**
 * Le tre viste applicative (Overview, Assistant, Co-Pilot) diventano rotte di
 * primo livello con lazy loading. Lo store di stato e' a singleton di root,
 * quindi il passaggio tra viste resta istantaneo come nella SPA originale:
 * il routing aggiunge solo l'indirizzabilita' dell'URL, senza ricaricare i dati.
 */
export const routes: Routes = [
  { path: "", pathMatch: "full", redirectTo: "overview" },
  {
    path: "overview",
    title: "NEXUM - Overview",
    data: { view: "overview" satisfies PocView },
    loadComponent: () => import("./features/overview/overview-page").then((m) => m.OverviewPage)
  },
  {
    path: "assistant",
    title: "NEXUM - AI Assistant",
    data: { view: "assistant" satisfies PocView },
    loadComponent: () => import("./features/assistant/assistant-page").then((m) => m.AssistantPage)
  },
  {
    path: "copilot",
    title: "NEXUM - Copilot CdL",
    data: { view: "copilot" satisfies PocView },
    loadComponent: () => import("./features/copilot/copilot-page").then((m) => m.CopilotPage)
  },
  { path: "**", redirectTo: "overview" }
];
