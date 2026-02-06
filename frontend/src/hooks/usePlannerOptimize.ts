import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { fetchPlannerOptimize, type PlannerOptimizeResponse, type ChipPlan } from '../api/client'

export function usePlannerOptimize(
  managerId: number | null,
  freeTransfers: number = 1,
  chipPlan: ChipPlan = {},
  xMinsOverrides: Record<number, number> = {}
) {
  return useQuery<PlannerOptimizeResponse>({
    queryKey: [
      'planner-optimize',
      managerId,
      freeTransfers,
      JSON.stringify(chipPlan),
      JSON.stringify(xMinsOverrides),
    ],
    queryFn: () => fetchPlannerOptimize(managerId!, freeTransfers, chipPlan, xMinsOverrides),
    enabled: managerId !== null && managerId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
    placeholderData: keepPreviousData,
  })
}
