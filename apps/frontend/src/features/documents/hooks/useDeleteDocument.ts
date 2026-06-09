import { useMutation, useQueryClient } from "@tanstack/react-query";
import { deletePocSubDocument } from "../../../api/pocApi";
import { pocStateQueryKey } from "../../state/hooks/usePocState";
import { getDocumentDeleteId } from "../utils/documentIds";

export function useDeleteDocument(onDeleted?: () => void) {
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: (documentId: string) => deletePocSubDocument(getDocumentDeleteId(documentId)),
    onSuccess: (response) => {
      queryClient.setQueryData(pocStateQueryKey, response.state);
      onDeleted?.();
    }
  });

  return {
    deleteDocument: mutation.mutate,
    isDeleting: mutation.isPending
  };
}
