import { useQuery } from '@tanstack/react-query'
import { fetchLeagueAnalysis, type LeagueAnalysisResponse } from '../api/client'

export function useLeagueAnalysis(leagueId: number | null, gameweek?: number) {
  return useQuery<LeagueAnalysisResponse>({
    queryKey: ['league-analysis', leagueId, gameweek],
    queryFn: () => fetchLeagueAnalysis(leagueId!, gameweek),
    enabled: leagueId !== null && leagueId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
