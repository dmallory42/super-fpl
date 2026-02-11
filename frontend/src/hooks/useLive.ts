import { useQuery } from '@tanstack/react-query'
import { fetchLiveData, fetchLiveManager, fetchLiveBonus } from '../api/client'

interface LivePollingContext {
  isLive?: boolean
  matchesInProgress?: number
}

function getLiveRefetchInterval({ isLive, matchesInProgress }: LivePollingContext): number {
  if (!isLive) return 300000 // 5 minutes when GW is not active
  if ((matchesInProgress ?? 0) > 0) return 30000 // 30s when matches are currently live
  return 60000 // 60s when GW window is active but no live match
}

export function useLiveData(gameweek: number | null, context: LivePollingContext = {}) {
  return useQuery({
    queryKey: ['live', gameweek],
    queryFn: () => fetchLiveData(gameweek!),
    enabled: gameweek !== null && gameweek > 0,
    refetchInterval: getLiveRefetchInterval(context),
    staleTime: 30000,
  })
}

export function useLiveManager(
  gameweek: number | null,
  managerId: number | null,
  context: LivePollingContext = {}
) {
  return useQuery({
    queryKey: ['live-manager', gameweek, managerId],
    queryFn: () => fetchLiveManager(gameweek!, managerId!),
    enabled: gameweek !== null && gameweek > 0 && managerId !== null && managerId > 0,
    refetchInterval: getLiveRefetchInterval(context),
    staleTime: 30000,
  })
}

export function useLiveBonus(gameweek: number | null, context: LivePollingContext = {}) {
  return useQuery({
    queryKey: ['live-bonus', gameweek],
    queryFn: () => fetchLiveBonus(gameweek!),
    enabled: gameweek !== null && gameweek > 0,
    refetchInterval: getLiveRefetchInterval(context),
    staleTime: 30000,
  })
}
