import { useQuery } from '@tanstack/react-query'
import { fetchComparison, type ComparisonResponse } from '../api/client'

export function useCompare(managerIds: number[], gameweek?: number) {
  return useQuery<ComparisonResponse>({
    queryKey: ['compare', managerIds, gameweek],
    queryFn: () => fetchComparison(managerIds, gameweek),
    enabled: managerIds.length >= 2,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
