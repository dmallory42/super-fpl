import { useQuery } from '@tanstack/react-query'
import { fetchLeague, type LeagueResponse } from '../api/client'

export function useLeague(leagueId: number | null, page = 1) {
  return useQuery<LeagueResponse>({
    queryKey: ['league', leagueId, page],
    queryFn: () => fetchLeague(leagueId!, page),
    enabled: leagueId !== null && leagueId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
