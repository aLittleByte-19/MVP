import type { Metric } from "../../../api/generated/model";

export function useMetrics(metrics: Metric[] | undefined) {
  return metrics ?? [];
}
