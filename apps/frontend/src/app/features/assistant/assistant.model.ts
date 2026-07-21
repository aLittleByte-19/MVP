import type { GenerateCommunicationRequest } from "../../../api/generated/model";

export type CommunicationDraftForm = GenerateCommunicationRequest;

export const RATING_COMMENT_MAX_LENGTH = 1000;

export interface GeneratedDraft {
  id: number;
  body: string;
  status: string;
  title: string;
  rating?: number | null;
  ratingComment?: string | null;
  ratedAt?: string | null;
}

export interface RateDraftPayload {
  rating: number;
  comment?: string;
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
