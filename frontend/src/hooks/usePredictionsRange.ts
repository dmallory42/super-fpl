import { useQuery } from '@tanstack/react-query'
import { fetchPredictionsRange, type PredictionsRangeResponse } from '../api/client'

export function usePredictionsRange(startGw?: number, endGw?: number, enabled = true) {
  return useQuery<PredictionsRangeResponse>({
    queryKey: ['predictions-range', startGw, endGw],
    queryFn: () => fetchPredictionsRange(startGw, endGw),
    staleTime: 1000 * 60 * 10, // 10 minutes - predictions don't change often
    enabled,
  })
}
