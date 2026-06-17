import { useMutation, useQueryClient } from "@tanstack/react-query";
import { reviewPocSubDocument, updatePocSubDocumentExtractedData } from "../../../api/pocApi";
import type { UpdateExtractedDataRequest } from "../../../api/generated/model";
import { getErrorMessage } from "../../../lib/errors";
import { pocStateQueryKey } from "../../state/hooks/usePocState";
import { getDocumentDeleteId } from "../utils/documentIds";

type SaveReviewPayload = {
  documentId: string;
  payload: UpdateExtractedDataRequest;
};

export function useReviewSubDocument(onReviewed?: (documentId: string) => void) {
  const queryClient = useQueryClient();

  const saveMutation = useMutation({
    mutationFn: ({ documentId, payload }: SaveReviewPayload) =>
      updatePocSubDocumentExtractedData(getDocumentDeleteId(documentId), payload),
    onSuccess: (response) => {
      queryClient.setQueryData(pocStateQueryKey, response.state);
      onReviewed?.(response.document.id);
    }
  });

  const validateMutation = useMutation({
    mutationFn: (documentId: string) => reviewPocSubDocument(getDocumentDeleteId(documentId)),
    onSuccess: (response) => {
      queryClient.setQueryData(pocStateQueryKey, response.state);
      onReviewed?.(response.document.id);
    }
  });

  return {
    isSavingReview: saveMutation.isPending || validateMutation.isPending,
    reviewError: saveMutation.error ? getErrorMessage(saveMutation.error) : validateMutation.error ? getErrorMessage(validateMutation.error) : null,
    saveReview: saveMutation.mutate,
    markReviewed: validateMutation.mutate
  };
}
