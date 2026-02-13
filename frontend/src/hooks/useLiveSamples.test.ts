import { describe, it, expect } from 'vitest'
import { calculateComparisons, getPlayerEO, getSampleProvenance } from './useLiveSamples'
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

describe('getSampleProvenance', () => {
  it('returns real source and non-stale for fresh updates', () => {
    const samples: LiveSamplesResponse = {
      gameweek: 24,
      samples: {
        top_10k: {
          avg_points: 68,
          sample_size: 2500,
          effective_ownership: {},
          estimated: false,
        },
      },
      updated_at: '2026-02-13T11:00:00Z',
    }

    const result = getSampleProvenance('top_10k', samples, true, Date.parse('2026-02-13T11:01:30Z'))
    expect(result?.source).toBe('real')
    expect(result?.sampleSize).toBe(2500)
    expect(result?.isStale).toBe(false)
  })

  it('returns estimated source and stale when update age exceeds threshold', () => {
    const samples: LiveSamplesResponse = {
      gameweek: 24,
      samples: {
        top_10k: {
          avg_points: 68,
          sample_size: 150,
          effective_ownership: {},
          estimated: true,
        },
      },
      updated_at: '2026-02-13T10:50:00Z',
    }

    const result = getSampleProvenance('top_10k', samples, true, Date.parse('2026-02-13T11:00:00Z'))
    expect(result?.source).toBe('estimated')
    expect(result?.isStale).toBe(true)
    expect(result?.ageSeconds).toBe(600)
  })
})
