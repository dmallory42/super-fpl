import { useEffect, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchSyncStatus } from '../api/client'

/**
 * Polls /sync/status every 30s. When last_sync changes,
 * invalidates all cached queries so the UI shows fresh data.
 */
export function useSyncStatus() {
  const queryClient = useQueryClient()
  const lastSyncRef = useRef<number | null>(null)

  const { data } = useQuery({
    queryKey: ['sync-status'],
    queryFn: fetchSyncStatus,
    refetchInterval: 30_000,
    staleTime: 25_000,
  })

  useEffect(() => {
    if (data == null) return

    const { last_sync } = data

    // First load — store the value but don't invalidate
    if (lastSyncRef.current === null) {
      lastSyncRef.current = last_sync
      return
    }

    // Sync timestamp changed — invalidate everything except our own query
    if (last_sync !== lastSyncRef.current) {
      lastSyncRef.current = last_sync
      queryClient.invalidateQueries({
        predicate: (query) => query.queryKey[0] !== 'sync-status',
      })
    }
  }, [data, queryClient])
}
