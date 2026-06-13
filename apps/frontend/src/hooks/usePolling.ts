import { useEffect } from "react";

export function usePolling(callback: () => void, intervalMs: number | null) {
  useEffect(() => {
    if (intervalMs === null) {
      return undefined;
    }

    const interval = window.setInterval(callback, intervalMs);

    return () => window.clearInterval(interval);
  }, [callback, intervalMs]);
}
