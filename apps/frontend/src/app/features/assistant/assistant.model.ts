import type { Communication, GenerateCommunicationRequest } from "../../../api/generated/model";

export type CommunicationDraftForm = GenerateCommunicationRequest;

/** Fase della pipeline di generazione, usata per etichette e stato dei controlli. */
export type CommunicationGenerationPhase =
  | "queued"
  | "generating-text"
  | "generating-cover"
  | "completed"
  | "failed";

export interface CommunicationGenerationProgress {
  status: string;
  phase: CommunicationGenerationPhase;
  communicationId: number;
  text?: { title: string | null; body: string | null };
  cover?: { coverImageUrl: string | null; coverStatus: string; coverError: string | null };
  communication?: Communication;
}

export interface GeneratedDraft {
  id: number;
  body: string;
  coverImageUrl?: string;
  coverStatus: string;
  coverError?: string;
  status: string;
  title: string;
  previewUrl?: string;
  exportUrl?: string;
  generationStatus?: string;
}

export const communicationTones = [
  "Chiaro e diretto",
  "Più istituzionale",
  "Più sintetico",
  "Empatico",
  "Tecnico"
] as const;

export const communicationStyles = [
  "Testo informativo",
  "Avviso operativo",
  "Aggiornamento breve"
] as const;
