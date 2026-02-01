import { useQuery } from '@tanstack/react-query'
import { fetchPredictions, type PredictionsResponse } from '../api/client'

export function usePredictions(gameweek: number | null) {
  return useQuery<PredictionsResponse>({
    queryKey: ['predictions', gameweek],
    queryFn: () => fetchPredictions(gameweek!),
    enabled: gameweek !== null && gameweek > 0,
    staleTime: 1000 * 60 * 10, // 10 minutes - predictions don't change often
  })
}
