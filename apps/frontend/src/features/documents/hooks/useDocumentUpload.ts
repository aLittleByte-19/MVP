import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { uploadPocDocument } from "../../../api/pocApi";
import type { PocState, SubDocument } from "../../../api/generated/model";
import { getErrorMessage } from "../../../lib/errors";
import { pocStateQueryKey } from "../../state/hooks/usePocState";

type UseDocumentUploadOptions = {
  onDocumentReceived: (documentId: string) => void;
};

export function useDocumentUpload({ onDocumentReceived }: UseDocumentUploadOptions) {
  const queryClient = useQueryClient();
  const eventSourceRef = useRef<EventSource | null>(null);
  const [status, setStatus] = useState("Nessun caricamento in corso.");

  useEffect(() => {
    return () => eventSourceRef.current?.close();
  }, []);

  const mutation = useMutation({
    mutationFn: (file: File) => uploadPocDocument({ document: file }),
    onMutate: () => {
      setStatus("Upload avviato.");
      eventSourceRef.current?.close();
    },
    onSuccess: (response) => {
      setStatus(response.message);

      const events = new EventSource(response.streamUrl);
      eventSourceRef.current = events;

      events.addEventListener("document", (event) => {
        const documentItem = JSON.parse((event as MessageEvent).data) as SubDocument;

        queryClient.setQueryData<PocState>(pocStateQueryKey, (current) => {
          if (!current) {
            return current;
          }

          return {
            ...current,
            copilot: {
              ...current.copilot,
              documents: [
                documentItem,
                ...current.copilot.documents.filter((item) => item.id !== documentItem.id)
              ]
            }
          };
        });
        onDocumentReceived(documentItem.id);
      });

      events.addEventListener("done", (event) => {
        const payload = JSON.parse((event as MessageEvent).data) as { state?: PocState };

        if (payload.state) {
          queryClient.setQueryData(pocStateQueryKey, payload.state);
        }

        setStatus("Elaborazione completata.");
        events.close();
        eventSourceRef.current = null;
      });

      events.addEventListener("error", () => {
        setStatus("Elaborazione non disponibile. Controlla lo stato del documento.");
        events.close();
        eventSourceRef.current = null;
        void queryClient.invalidateQueries({ queryKey: pocStateQueryKey });
      });
    },
    onError: (error) => {
      setStatus(getErrorMessage(error));
    }
  });

  return {
    isUploading: mutation.isPending,
    status,
    upload: mutation.mutate
  };
}
