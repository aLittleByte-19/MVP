import type { SidebarNavGroup } from "../components/layout/SidebarNav";

export type PocView = "overview" | "assistant" | "copilot";

export const pocNavGroups: SidebarNavGroup<PocView>[] = [
  {
    title: "Panoramica",
    items: [
      {
        id: "overview",
        label: "Overview",
        children: [
          { label: "Moduli", targetId: "overview-modules" },
          { label: "Priorita", targetId: "overview-priorities" }
        ]
      }
    ]
  },
  {
    title: "AI Assistant",
    items: [
      {
        id: "assistant",
        label: "Assistant",
        children: [
          { label: "Generazione", targetId: "assistant-compose" },
          { label: "Revisione", targetId: "assistant-review" },
          { label: "Storico contenuti", targetId: "assistant-history" }
        ]
      }
    ]
  },
  {
    title: "Co-Pilot CdL",
    items: [
      {
        id: "copilot",
        label: "Co-Pilot",
        children: [
          { label: "Caricamento", targetId: "copilot-upload" },
          { label: "Storico documenti", targetId: "copilot-documents" },
          { label: "Metriche", targetId: "copilot-metrics" }
        ]
      }
    ]
  }
];

export const pocViewTitles: Record<PocView, string> = {
  overview: "Overview operativa",
  assistant: "AI Assistant Generativo",
  copilot: "AI Co-Pilot per i CdL"
};
