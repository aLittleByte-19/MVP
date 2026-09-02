import type { Routes } from "@angular/router";
import type { MvpView } from "./core/navigation/app-views";

/** Rotte lazy-loaded di primo livello; lo store di stato e' a singleton di root, quindi il routing aggiunge solo indirizzabilita' URL senza ricaricare i dati. */
export const routes: Routes = [
  { path: "", pathMatch: "full", redirectTo: "overview" },
  {
    path: "overview",
    title: "NEXUM - Overview",
    data: { view: "overview" satisfies MvpView },
    loadComponent: () => import("./features/overview/overview-page").then((m) => m.OverviewPage)
  },
  {
    path: "assistant",
    title: "NEXUM - AI Assistant",
    data: { view: "assistant" satisfies MvpView },
    loadComponent: () => import("./features/assistant/assistant-page").then((m) => m.AssistantPage)
  },
  {
    path: "copilot",
    title: "NEXUM - Copilot CdL",
    data: { view: "copilot" satisfies MvpView },
    loadComponent: () => import("./features/copilot/copilot-page").then((m) => m.CopilotPage)
  },
  { path: "**", redirectTo: "overview" }
];
