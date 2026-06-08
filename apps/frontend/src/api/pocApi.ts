import {
  deletePocSubDocument as deletePocSubDocumentGenerated,
  generatePocCommunication as generatePocCommunicationGenerated,
  getPocState as getPocStateGenerated,
  uploadPocDocument as uploadPocDocumentGenerated
} from "./generated/poc-api";
import type {
  DeleteDocumentResponse,
  GenerateCommunicationRequest,
  GenerateCommunicationResponse,
  PocState,
  UploadDocumentResponse,
  UploadPocDocumentBody
} from "./generated/model";

type PocGeneratedResponse = {
  data: unknown;
  status: number;
};

function assertPocSuccess<TData>(response: PocGeneratedResponse): TData {
  if (response.status >= 200 && response.status < 300) {
    return response.data as TData;
  }

  throw response.data;
}

export async function getPocState(): Promise<PocState> {
  return assertPocSuccess<PocState>(await getPocStateGenerated());
}

export async function generatePocCommunication(
  payload: GenerateCommunicationRequest
): Promise<GenerateCommunicationResponse> {
  return assertPocSuccess<GenerateCommunicationResponse>(await generatePocCommunicationGenerated(payload));
}

export async function uploadPocDocument(payload: UploadPocDocumentBody): Promise<UploadDocumentResponse> {
  return assertPocSuccess<UploadDocumentResponse>(await uploadPocDocumentGenerated(payload));
}

export async function deletePocSubDocument(subDocument: number): Promise<DeleteDocumentResponse> {
  return assertPocSuccess<DeleteDocumentResponse>(await deletePocSubDocumentGenerated(subDocument));
}
