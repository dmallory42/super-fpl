import { useQuery } from '@tanstack/react-query'
import {
  fetchManager,
  fetchManagerPicks,
  fetchManagerHistory,
  fetchManagerSeasonAnalysis,
  type ManagerHistoryResponse,
  type ManagerSeasonAnalysisResponse,
} from '../api/client'
import type { Manager, ManagerPicks } from '../types'

export function useManager(id: number | null) {
  return useQuery<Manager>({
    queryKey: ['manager', id],
    queryFn: () => fetchManager(id!),
    enabled: id !== null && id > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}

export function useManagerPicks(id: number | null, gameweek: number | null) {
  return useQuery<ManagerPicks>({
    queryKey: ['manager-picks', id, gameweek],
    queryFn: () => fetchManagerPicks(id!, gameweek!),
    enabled: id !== null && id > 0 && gameweek !== null && gameweek > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}

export function useManagerHistory(id: number | null) {
  return useQuery<ManagerHistoryResponse>({
    queryKey: ['manager-history', id],
    queryFn: () => fetchManagerHistory(id!),
    enabled: id !== null && id > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}

export function useManagerSeasonAnalysis(id: number | null) {
  return useQuery<ManagerSeasonAnalysisResponse>({
    queryKey: ['manager-season-analysis', id],
    queryFn: () => fetchManagerSeasonAnalysis(id!),
    enabled: id !== null && id > 0,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
