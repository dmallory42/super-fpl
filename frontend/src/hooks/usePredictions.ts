import { useQuery } from '@tanstack/react-query'
import { fetchPredictions, type PredictionsResponse } from '../api/client'

export function usePredictions(gameweek?: number | null) {
  return useQuery<PredictionsResponse>({
    queryKey: ['predictions', gameweek ?? 'current'],
    queryFn: () => fetchPredictions(gameweek ?? undefined),
    staleTime: 1000 * 60 * 10, // 10 minutes - predictions don't change often
  })
}
