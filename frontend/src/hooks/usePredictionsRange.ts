import { useQuery, keepPreviousData } from '@tanstack/react-query'
import {
  fetchPredictionsRange,
  type PredictionsRangeResponse,
  type XMinsOverrides,
} from '../api/client'

export function usePredictionsRange(
  startGw?: number,
  endGw?: number,
  enabled = true,
  xMinsOverrides: XMinsOverrides = {}
) {
  return useQuery<PredictionsRangeResponse>({
    queryKey: ['predictions-range', startGw, endGw, xMinsOverrides],
    queryFn: () => fetchPredictionsRange(startGw, endGw, xMinsOverrides),
    staleTime: 1000 * 60 * 10, // 10 minutes - predictions don't change often
    placeholderData: keepPreviousData,
    enabled,
  })
}
