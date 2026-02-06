import { describe, it, expect } from 'vitest'
import { formatRank } from './format'

describe('formatRank', () => {
  it('returns dash for null', () => {
    expect(formatRank(null)).toBe('-')
  })

  it('formats millions with one decimal', () => {
    expect(formatRank(1000000)).toBe('1.0M')
    expect(formatRank(2500000)).toBe('2.5M')
    expect(formatRank(10000000)).toBe('10.0M')
  })

  it('formats tens of thousands as rounded K', () => {
    expect(formatRank(10000)).toBe('10K')
    expect(formatRank(50000)).toBe('50K')
    expect(formatRank(99999)).toBe('100K')
  })

  it('formats thousands with one decimal K', () => {
    expect(formatRank(1000)).toBe('1.0K')
    expect(formatRank(5500)).toBe('5.5K')
    expect(formatRank(9999)).toBe('10.0K')
  })

  it('formats small numbers with locale string', () => {
    expect(formatRank(1)).toBe('1')
    expect(formatRank(999)).toBe('999')
  })
})
