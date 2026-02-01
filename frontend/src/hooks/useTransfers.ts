import { useQuery } from '@tanstack/react-query'
import { fetchTransferSuggestions, fetchTransferTargets, fetchTransferSimulate } from '../api/client'

export function useTransferSuggestions(managerId: number | null, gameweek?: number, transfers = 1) {
  return useQuery({
    queryKey: ['transfer-suggestions', managerId, gameweek, transfers],
    queryFn: () => fetchTransferSuggestions(managerId!, gameweek, transfers),
    enabled: managerId !== null && managerId > 0,
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export function useTransferTargets(gameweek?: number, position?: number, maxPrice?: number) {
  return useQuery({
    queryKey: ['transfer-targets', gameweek, position, maxPrice],
    queryFn: () => fetchTransferTargets(gameweek, position, maxPrice),
    staleTime: 5 * 60 * 1000,
  })
}

export function useTransferSimulate(
  managerId: number | null,
  outPlayerId: number | null,
  inPlayerId: number | null,
  gameweek?: number
) {
  return useQuery({
    queryKey: ['transfer-simulate', managerId, outPlayerId, inPlayerId, gameweek],
    queryFn: () => fetchTransferSimulate(managerId!, outPlayerId!, inPlayerId!, gameweek),
    enabled: managerId !== null && outPlayerId !== null && inPlayerId !== null,
    staleTime: 5 * 60 * 1000,
  })
}
