import { useQuery, keepPreviousData } from '@tanstack/react-query'
import {
  fetchPlannerOptimize,
  type PlannerOptimizeResponse,
  type ChipPlan,
  type FixedTransfer,
  type SolverDepth,
} from '../api/client'

export function usePlannerOptimize(
  managerId: number | null,
  freeTransfers: number = 1,
  chipPlan: ChipPlan = {},
  xMinsOverrides: Record<number, number> = {},
  fixedTransfers: FixedTransfer[] = [],
  ftValue: number = 1.5,
  depth: SolverDepth = 'standard'
) {
  return useQuery<PlannerOptimizeResponse>({
    queryKey: [
      'planner-optimize',
      managerId,
      freeTransfers,
      JSON.stringify(chipPlan),
      JSON.stringify(xMinsOverrides),
      JSON.stringify(fixedTransfers),
      ftValue,
      depth,
    ],
    queryFn: () =>
      fetchPlannerOptimize(
        managerId!,
        freeTransfers,
        chipPlan,
        xMinsOverrides,
        fixedTransfers,
        ftValue,
        depth
      ),
    enabled: managerId !== null && managerId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
    placeholderData: keepPreviousData,
  })
}
