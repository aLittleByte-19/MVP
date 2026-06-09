import { useState } from "react";

export function useAsyncAction(initialStatus: string) {
  const [status, setStatus] = useState(initialStatus);

  return { status, setStatus };
}
