import {
  deletePocSubDocument as deletePocSubDocumentGenerated,
  generatePocCommunication as generatePocCommunicationGenerated,
  getPocState as getPocStateGenerated,
  reviewPocSubDocument as reviewPocSubDocumentGenerated,
  updatePocSubDocumentExtractedData as updatePocSubDocumentExtractedDataGenerated,
  uploadPocDocument as uploadPocDocumentGenerated
} from "./generated/poc-api";
import type {
  DeleteDocumentResponse,
  GenerateCommunicationRequest,
  GenerateCommunicationResponse,
  PocState,
  UpdateExtractedDataRequest,
  UpdateSubDocumentReviewResponse,
  UploadDocumentResponse,
  UploadPocDocumentBody
} from "./generated/model";
import { assertApiSuccess } from "../lib/errors";

export async function getPocState(): Promise<PocState> {
  return assertApiSuccess<PocState>(await getPocStateGenerated());
}

export async function generatePocCommunication(
  payload: GenerateCommunicationRequest
): Promise<GenerateCommunicationResponse> {
  return assertApiSuccess<GenerateCommunicationResponse>(await generatePocCommunicationGenerated(payload));
}

export async function uploadPocDocument(payload: UploadPocDocumentBody): Promise<UploadDocumentResponse> {
  return assertApiSuccess<UploadDocumentResponse>(await uploadPocDocumentGenerated(payload));
}

export async function deletePocSubDocument(subDocument: number): Promise<DeleteDocumentResponse> {
  return assertApiSuccess<DeleteDocumentResponse>(await deletePocSubDocumentGenerated(subDocument));
}

export async function updatePocSubDocumentExtractedData(
  subDocument: number,
  payload: UpdateExtractedDataRequest
): Promise<UpdateSubDocumentReviewResponse> {
  return assertApiSuccess<UpdateSubDocumentReviewResponse>(
    await updatePocSubDocumentExtractedDataGenerated(subDocument, payload)
  );
}

export async function reviewPocSubDocument(subDocument: number): Promise<UpdateSubDocumentReviewResponse> {
  return assertApiSuccess<UpdateSubDocumentReviewResponse>(await reviewPocSubDocumentGenerated(subDocument));
}
