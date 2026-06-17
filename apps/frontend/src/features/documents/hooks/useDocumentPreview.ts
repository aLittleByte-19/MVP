import { useEffect, useState } from "react";

/**
 * Stato applicativo dell'anteprima PDF di un sotto-documento.
 * L'endpoint di preview puo' rispondere con il PDF (200), 404 se il file non
 * esiste o 503 JSON se lo storage non e' raggiungibile: si verifica il
 * content-type prima di montare l'iframe, evitando di mostrare errori tecnici.
 */
export type DocumentPreviewStatus = "idle" | "loading" | "available" | "unavailable" | "unreachable";

export function useDocumentPreview(previewUrl?: string | null): DocumentPreviewStatus {
  const [status, setStatus] = useState<DocumentPreviewStatus>("idle");

  useEffect(() => {
    if (!previewUrl) {
      setStatus("idle");

      return;
    }

    let cancelled = false;
    setStatus("loading");

    fetch(previewUrl, { credentials: "include" })
      .then((response) => {
        if (cancelled) {
          return;
        }

        const contentType = response.headers.get("content-type") ?? "";

        if (response.ok && contentType.includes("application/pdf")) {
          setStatus("available");
        } else if (response.status === 503) {
          setStatus("unreachable");
        } else {
          setStatus("unavailable");
        }
      })
      .catch(() => {
        if (!cancelled) {
          setStatus("unreachable");
        }
      });

    return () => {
      cancelled = true;
    };
  }, [previewUrl]);

  return status;
}
