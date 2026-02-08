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
  freeTransfers: number | null = null,
  chipPlan: ChipPlan = {},
  xMinsOverrides: Record<number, number> = {},
  fixedTransfers: FixedTransfer[] = [],
  ftValue: number = 1.5,
  depth: SolverDepth = 'standard',
  skipSolve: boolean = false
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
      skipSolve,
    ],
    queryFn: () =>
      fetchPlannerOptimize(
        managerId!,
        freeTransfers,
        chipPlan,
        xMinsOverrides,
        fixedTransfers,
        ftValue,
        depth,
        skipSolve
      ),
    enabled: managerId !== null && managerId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
    placeholderData: keepPreviousData,
  })
}
