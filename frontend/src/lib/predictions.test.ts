import { scalePoints } from './predictions'

describe('scalePoints', () => {
  it('returns exact points when effectiveMins equals ifFitMins', () => {
    expect(scalePoints(11.7, 84, 84)).toBeCloseTo(11.7)
  })

  it('scales up when effectiveMins exceeds ifFitMins', () => {
    expect(scalePoints(11.7, 84, 85)).toBeCloseTo(11.84, 1)
  })

  it('returns zero when effectiveMins is zero', () => {
    expect(scalePoints(6.0, 82, 0)).toBe(0)
  })

  it('returns zero when ifFitMins is zero', () => {
    expect(scalePoints(6.0, 0, 80)).toBe(0)
  })

  it('scales injured player with override', () => {
    // Injured player: ifFitPts=6, ifFitMins=82, user override=80
    expect(scalePoints(6.0, 82, 80)).toBeCloseTo(5.85, 1)
  })

  it('handles negative ifFitMins', () => {
    expect(scalePoints(5.0, -1, 80)).toBe(0)
  })
})
