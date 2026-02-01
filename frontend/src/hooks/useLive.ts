import { useQuery } from '@tanstack/react-query'
import { fetchLiveData, fetchLiveManager, fetchLiveBonus } from '../api/client'

export function useLiveData(gameweek: number | null) {
  return useQuery({
    queryKey: ['live', gameweek],
    queryFn: () => fetchLiveData(gameweek!),
    enabled: gameweek !== null && gameweek > 0,
    refetchInterval: 60000, // Refetch every 60 seconds for live data
    staleTime: 30000,
  })
}

export function useLiveManager(gameweek: number | null, managerId: number | null) {
  return useQuery({
    queryKey: ['live-manager', gameweek, managerId],
    queryFn: () => fetchLiveManager(gameweek!, managerId!),
    enabled: gameweek !== null && gameweek > 0 && managerId !== null && managerId > 0,
    refetchInterval: 60000,
    staleTime: 30000,
  })
}

export function useLiveBonus(gameweek: number | null) {
  return useQuery({
    queryKey: ['live-bonus', gameweek],
    queryFn: () => fetchLiveBonus(gameweek!),
    enabled: gameweek !== null && gameweek > 0,
    refetchInterval: 60000,
    staleTime: 30000,
  })
}
