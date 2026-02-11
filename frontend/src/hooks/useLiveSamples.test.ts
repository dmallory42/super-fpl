import { describe, it, expect } from 'vitest'
import { calculateComparisons, getPlayerEO } from './useLiveSamples'
import type { LiveSamplesResponse } from '../api/client'

describe('calculateComparisons', () => {
  it('returns comparisons sorted by tier priority', () => {
    const samples: LiveSamplesResponse = {
      gameweek: 24,
      samples: {
        overall: {
          avg_points: 45,
          sample_size: 2000,
          effective_ownership: {},
        },
        top_1m: {
          avg_points: 52,
          sample_size: 2000,
          effective_ownership: {},
        },
        top_10k: {
          avg_points: 68,
          sample_size: 2000,
          effective_ownership: {},
        },
        top_100k: {
          avg_points: 61,
          sample_size: 2000,
          effective_ownership: {},
        },
      },
      updated_at: '2026-02-11T11:00:00Z',
    }

    const result = calculateComparisons(70, samples)

    expect(result.map((row) => row.tier)).toEqual(['top_10k', 'top_100k', 'top_1m', 'overall'])
    expect(result[0]?.difference).toBe(2)
  })
})

describe('getPlayerEO', () => {
  it('returns EO for a player and null when unavailable', () => {
    const samples: LiveSamplesResponse = {
      gameweek: 24,
      samples: {
        top_10k: {
          avg_points: 68,
          sample_size: 2000,
          effective_ownership: {
            123: 95.5,
          },
        },
      },
      updated_at: '2026-02-11T11:00:00Z',
    }

    expect(getPlayerEO(123, 'top_10k', samples)).toBe(95.5)
    expect(getPlayerEO(456, 'top_10k', samples)).toBeNull()
    expect(getPlayerEO(123, 'overall', samples)).toBeNull()
  })
})
