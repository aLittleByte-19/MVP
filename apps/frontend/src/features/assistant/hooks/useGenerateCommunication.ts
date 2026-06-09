import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { generatePocCommunication } from "../../../api/pocApi";
import { getErrorMessage } from "../../../lib/errors";
import { pocStateQueryKey } from "../../state/hooks/usePocState";
import type { CommunicationDraftForm, GeneratedDraft } from "../types";

export function useGenerateCommunication(onGenerated?: () => void) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState("In attesa di istruzioni.");
  const [draft, setDraft] = useState<GeneratedDraft | null>(null);

  const mutation = useMutation({
    mutationFn: (payload: CommunicationDraftForm) => generatePocCommunication(payload),
    onSuccess: (response) => {
      setDraft({
        title: response.communication.title,
        body: response.communication.body,
        status: response.communication.status
      });
      setStatus(response.message);
      queryClient.setQueryData(pocStateQueryKey, response.state);
      onGenerated?.();
    },
    onError: (error) => {
      setStatus(getErrorMessage(error));
    }
  });

  return {
    draft,
    generate: mutation.mutate,
    isGenerating: mutation.isPending,
    status
  };
}
