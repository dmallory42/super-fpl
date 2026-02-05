import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useLiveData, useLiveManager, useLiveBonus } from './useLive'
import * as client from '../api/client'

// Mock the API client
vi.mock('../api/client', () => ({
  fetchLiveData: vi.fn(),
  fetchLiveManager: vi.fn(),
  fetchLiveBonus: vi.fn(),
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

describe('useLiveData', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('does not fetch when gameweek is null', () => {
    renderHook(() => useLiveData(null), { wrapper: createWrapper() })

    expect(client.fetchLiveData).not.toHaveBeenCalled()
  })

  it('does not fetch when gameweek is 0', () => {
    renderHook(() => useLiveData(0), { wrapper: createWrapper() })

    expect(client.fetchLiveData).not.toHaveBeenCalled()
  })

  it('fetches when gameweek is valid', async () => {
    const mockData = { elements: [] }
    vi.mocked(client.fetchLiveData).mockResolvedValueOnce(mockData)

    const { result } = renderHook(() => useLiveData(25), { wrapper: createWrapper() })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(client.fetchLiveData).toHaveBeenCalledWith(25)
    expect(result.current.data).toEqual(mockData)
  })
})

describe('useLiveManager', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not fetch when gameweek is null', () => {
    renderHook(() => useLiveManager(null, 1001), { wrapper: createWrapper() })

    expect(client.fetchLiveManager).not.toHaveBeenCalled()
  })

  it('does not fetch when managerId is null', () => {
    renderHook(() => useLiveManager(25, null), { wrapper: createWrapper() })

    expect(client.fetchLiveManager).not.toHaveBeenCalled()
  })

  it('does not fetch when managerId is 0', () => {
    renderHook(() => useLiveManager(25, 0), { wrapper: createWrapper() })

    expect(client.fetchLiveManager).not.toHaveBeenCalled()
  })

  it('fetches when both gameweek and managerId are valid', async () => {
    const mockData = {
      manager_id: 1001,
      gameweek: 25,
      total_points: 65,
      bench_points: 12,
      players: [],
      updated_at: '2024-01-15T12:00:00Z',
    }
    vi.mocked(client.fetchLiveManager).mockResolvedValueOnce(mockData)

    const { result } = renderHook(() => useLiveManager(25, 1001), { wrapper: createWrapper() })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(client.fetchLiveManager).toHaveBeenCalledWith(25, 1001)
    expect(result.current.data).toEqual(mockData)
  })
})

describe('useLiveBonus', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not fetch when gameweek is null', () => {
    renderHook(() => useLiveBonus(null), { wrapper: createWrapper() })

    expect(client.fetchLiveBonus).not.toHaveBeenCalled()
  })

  it('fetches when gameweek is valid', async () => {
    const mockData = {
      gameweek: 25,
      bonus_predictions: [{ player_id: 1, bps: 45, predicted_bonus: 3, fixture_id: 100 }],
    }
    vi.mocked(client.fetchLiveBonus).mockResolvedValueOnce(mockData)

    const { result } = renderHook(() => useLiveBonus(25), { wrapper: createWrapper() })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(client.fetchLiveBonus).toHaveBeenCalledWith(25)
    expect(result.current.data).toEqual(mockData)
  })
})
