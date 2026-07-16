/**
 * Modello di navigazione della SPA: voci, etichette e anchor della shell
 * operativa. Le sezioni figlie sono gli id DOM verso cui scorrere e che lo
 * scroll-spy evidenzia nella sidebar.
 */
export type MvpView = "overview" | "assistant" | "copilot";

export interface SidebarNavChild {
  label: string;
  targetId: string;
}

export interface SidebarNavItem {
  id: MvpView;
  label: string;
  children?: SidebarNavChild[];
}

export interface SidebarNavGroup {
  title: string;
  items: SidebarNavItem[];
}

export const mvpNavGroups: SidebarNavGroup[] = [
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

export const mvpViewTitles: Record<MvpView, string> = {
  overview: "Overview operativa",
  assistant: "AI Assistant Generativo",
  copilot: "AI Co-Pilot per i CdL"
};

export function isMvpView(value: unknown): value is MvpView {
  return value === "overview" || value === "assistant" || value === "copilot";
}
