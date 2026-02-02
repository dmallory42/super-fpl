import { useQuery } from '@tanstack/react-query'
import { fetchPlannerOptimize, type PlannerOptimizeResponse, type ChipPlan } from '../api/client'

export function usePlannerOptimize(
  managerId: number | null,
  freeTransfers: number = 1,
  chipPlan: ChipPlan = {}
) {
  return useQuery<PlannerOptimizeResponse>({
    queryKey: ['planner-optimize', managerId, freeTransfers, chipPlan],
    queryFn: () => fetchPlannerOptimize(managerId!, freeTransfers, chipPlan),
    enabled: managerId !== null && managerId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
