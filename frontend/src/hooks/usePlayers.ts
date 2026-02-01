import { useQuery } from '@tanstack/react-query'
import { fetchPlayers, type PlayersResponse } from '../api/client'

export function usePlayers() {
  return useQuery<PlayersResponse>({
    queryKey: ['players'],
    queryFn: fetchPlayers,
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}
