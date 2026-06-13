export type ApiErrorPayload = {
  error?: {
    message?: string;
    code?: string;
  };
};

export function getErrorMessage(error: unknown, fallback = "Operazione non disponibile."): string {
  if (error instanceof Error) {
    return error.message;
  }

  if (typeof error === "object" && error !== null && "error" in error) {
    const payload = error as ApiErrorPayload;

    return payload.error?.message || fallback;
  }

  return fallback;
}

export function assertApiSuccess<TData>(response: { data: unknown; status: number }): TData {
  if (response.status >= 200 && response.status < 300) {
    return response.data as TData;
  }

  throw response.data;
}
