export function getDocumentStatus(error?: string | null): "success" | "warning" {
  return error ? "warning" : "success";
}
