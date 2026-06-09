import { useQuery } from "@tanstack/react-query";
import { getPocState } from "../../../api/pocApi";

export const pocStateQueryKey = ["poc-state"] as const;

export function usePocState() {
  return useQuery({
    queryKey: pocStateQueryKey,
    queryFn: () => getPocState()
  });
}
