import { useQuery } from '@tanstack/react-query'
import { fetchFixturesStatus } from '../api/client'

export interface CurrentGameweekData {
  gameweek: number
  isLive: boolean
  matchesPlayed: number
  totalMatches: number
  matchesInProgress: number
}

export function useCurrentGameweek() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['fixtures-status'],
    queryFn: fetchFixturesStatus,
    refetchInterval: 60000, // Refetch every minute
    staleTime: 30000,
  })

  if (!data) {
    return {
      data: null,
      isLoading,
      error,
    }
  }

  const currentGw = data.gameweeks.find((gw) => gw.gameweek === data.current_gameweek)

  const result: CurrentGameweekData = {
    gameweek: data.current_gameweek,
    isLive: data.is_live,
    matchesPlayed: currentGw?.finished ?? 0,
    totalMatches: currentGw?.total ?? 0,
    matchesInProgress: (currentGw?.started ?? 0) - (currentGw?.finished ?? 0),
  }

  return {
    data: result,
    isLoading,
    error,
    // Also expose the full gameweek data for fixture status lookups
    gameweekData: currentGw,
    allGameweeks: data.gameweeks,
  }
}

/**
 * Get fixture status for a specific player's team
 */
export function usePlayerFixtureStatus(
  teamId: number | undefined,
  gameweekData: ReturnType<typeof useCurrentGameweek>['gameweekData']
): 'upcoming' | 'playing' | 'finished' | 'unknown' {
  if (!teamId || !gameweekData) return 'unknown'

  const fixture = gameweekData.fixtures.find(
    (f) => f.home_club_id === teamId || f.away_club_id === teamId
  )

  if (!fixture) return 'unknown'
  if (fixture.finished) return 'finished'
  if (fixture.started) return 'playing'
  return 'upcoming'
}
