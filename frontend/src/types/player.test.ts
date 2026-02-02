import { describe, it, expect } from 'vitest'
import { getPositionName, formatPrice, positionMap } from './player'

describe('getPositionName', () => {
  it('returns GKP for element type 1', () => {
    expect(getPositionName(1)).toBe('GKP')
  })

  it('returns DEF for element type 2', () => {
    expect(getPositionName(2)).toBe('DEF')
  })

  it('returns MID for element type 3', () => {
    expect(getPositionName(3)).toBe('MID')
  })

  it('returns FWD for element type 4', () => {
    expect(getPositionName(4)).toBe('FWD')
  })

  it('returns MID as default for unknown element type', () => {
    expect(getPositionName(99)).toBe('MID')
  })
})

describe('formatPrice', () => {
  it('formats price correctly for whole numbers', () => {
    expect(formatPrice(100)).toBe('10.0')
    expect(formatPrice(50)).toBe('5.0')
  })

  it('formats price correctly for decimal values', () => {
    expect(formatPrice(105)).toBe('10.5')
    expect(formatPrice(67)).toBe('6.7')
  })

  it('handles zero', () => {
    expect(formatPrice(0)).toBe('0.0')
  })
})

describe('positionMap', () => {
  it('has all four positions defined', () => {
    expect(Object.keys(positionMap)).toHaveLength(4)
    expect(positionMap[1]).toBe('GKP')
    expect(positionMap[2]).toBe('DEF')
    expect(positionMap[3]).toBe('MID')
    expect(positionMap[4]).toBe('FWD')
  })
})
