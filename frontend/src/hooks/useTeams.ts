import { useQuery } from '@tanstack/react-query'
import { fetchTeams } from '../api/client'

export function useTeams() {
  return useQuery({
    queryKey: ['teams'],
    queryFn: fetchTeams,
    staleTime: 24 * 60 * 60 * 1000, // 24 hours - teams rarely change
  })
}
