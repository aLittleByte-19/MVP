import type { GenerateCommunicationRequest } from "../../../api/generated/model";

export type CommunicationDraftForm = GenerateCommunicationRequest;

export interface GeneratedDraft {
  body: string;
  status: string;
  title: string;
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
