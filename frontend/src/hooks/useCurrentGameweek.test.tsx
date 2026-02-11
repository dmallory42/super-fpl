import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useCurrentGameweek, usePlayerFixtureStatus } from './useCurrentGameweek'
import * as client from '../api/client'

vi.mock('../api/client', () => ({
  fetchFixturesStatus: vi.fn(),
}))

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
    },
  })

  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )
}

describe('useCurrentGameweek', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('maps fixtures status response into current gameweek summary', async () => {
    vi.mocked(client.fetchFixturesStatus).mockResolvedValueOnce({
      current_gameweek: 24,
      is_live: true,
      gameweeks: [
        {
          gameweek: 24,
          fixtures: [
            {
              id: 1,
              kickoff_time: '2026-02-10T20:00:00Z',
              started: true,
              finished: true,
              minutes: 90,
              home_club_id: 1,
              away_club_id: 2,
              home_score: 2,
              away_score: 1,
            },
            {
              id: 2,
              kickoff_time: '2026-02-11T20:00:00Z',
              started: true,
              finished: false,
              minutes: 53,
              home_club_id: 3,
              away_club_id: 4,
              home_score: 0,
              away_score: 0,
            },
          ],
          total: 2,
          started: 2,
          finished: 1,
          first_kickoff: '2026-02-10T20:00:00Z',
          last_kickoff: '2026-02-11T20:00:00Z',
        },
      ],
    })

    const { result } = renderHook(() => useCurrentGameweek(), { wrapper: createWrapper() })

    await waitFor(() => {
      expect(result.current.data).not.toBeNull()
    })

    expect(result.current.data).toEqual({
      gameweek: 24,
      isLive: true,
      matchesPlayed: 1,
      totalMatches: 2,
      matchesInProgress: 1,
    })
  })
})

describe('usePlayerFixtureStatus', () => {
  it('returns fixture status based on team fixture progress', () => {
    const fixtureData = {
      gameweek: 24,
      fixtures: [
        {
          id: 1,
          kickoff_time: '2026-02-10T20:00:00Z',
          started: true,
          finished: true,
          minutes: 90,
          home_club_id: 1,
          away_club_id: 2,
          home_score: 1,
          away_score: 1,
        },
        {
          id: 2,
          kickoff_time: '2026-02-11T20:00:00Z',
          started: true,
          finished: false,
          minutes: 60,
          home_club_id: 3,
          away_club_id: 4,
          home_score: 2,
          away_score: 0,
        },
      ],
      total: 2,
      started: 2,
      finished: 1,
      first_kickoff: '2026-02-10T20:00:00Z',
      last_kickoff: '2026-02-11T20:00:00Z',
    }

    expect(usePlayerFixtureStatus(1, fixtureData)).toBe('finished')
    expect(usePlayerFixtureStatus(3, fixtureData)).toBe('playing')
    expect(usePlayerFixtureStatus(99, fixtureData)).toBe('unknown')
    expect(usePlayerFixtureStatus(undefined, fixtureData)).toBe('unknown')
  })
})
