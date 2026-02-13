import { useQuery } from '@tanstack/react-query'
import {
  fetchLeagueAnalysis,
  fetchLeagueSeasonAnalysis,
  type LeagueAnalysisResponse,
  type LeagueSeasonAnalysisParams,
  type LeagueSeasonAnalysisResponse,
} from '../api/client'

export function useLeagueAnalysis(leagueId: number | null, gameweek?: number) {
  return useQuery<LeagueAnalysisResponse>({
    queryKey: ['league-analysis', leagueId, gameweek],
    queryFn: () => fetchLeagueAnalysis(leagueId!, gameweek),
    enabled: leagueId !== null && leagueId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}

export function useLeagueSeasonAnalysis(
  leagueId: number | null,
  params: LeagueSeasonAnalysisParams = {}
) {
  const { gwFrom, gwTo, topN } = params

  return useQuery<LeagueSeasonAnalysisResponse>({
    queryKey: ['league-season-analysis', leagueId, gwFrom ?? null, gwTo ?? null, topN ?? null],
    queryFn: () => fetchLeagueSeasonAnalysis(leagueId!, { gwFrom, gwTo, topN }),
    enabled: leagueId !== null && leagueId > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
