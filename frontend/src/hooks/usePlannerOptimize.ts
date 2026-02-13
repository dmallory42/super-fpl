import { useQuery, keepPreviousData } from '@tanstack/react-query'
import {
  fetchPlannerOptimize,
  type PlannerOptimizeResponse,
  type ChipPlan,
  type ChipMode,
  type FixedTransfer,
  type PlannerObjectiveMode,
  type PlannerConstraints,
  type SolverDepth,
  type XMinsOverrides,
} from '../api/client'

export function usePlannerOptimize(
  managerId: number | null,
  freeTransfers: number | null = null,
  chipPlan: ChipPlan = {},
  xMinsOverrides: XMinsOverrides = {},
  fixedTransfers: FixedTransfer[] = [],
  ftValue: number = 1.5,
  depth: SolverDepth = 'standard',
  skipSolve: boolean = false,
  chipMode: ChipMode = 'locked',
  objectiveMode: PlannerObjectiveMode = 'expected',
  constraints: PlannerConstraints = {},
  chipCompare: boolean = false
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
      chipMode,
      objectiveMode,
      JSON.stringify(constraints),
      chipCompare,
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
        skipSolve,
        chipMode,
        objectiveMode,
        constraints,
        [],
        {},
        chipCompare
      ),
    enabled: managerId !== null && managerId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
    placeholderData: keepPreviousData,
  })
}
