import { useQuery } from '@tanstack/react-query'
import { fetchFixtures } from '../api/client'

export function useFixtures(gameweek?: number) {
  return useQuery({
    queryKey: ['fixtures', gameweek],
    queryFn: () => fetchFixtures(gameweek),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}
