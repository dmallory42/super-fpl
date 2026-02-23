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

  it('caps appearance and bonus when breakdown is provided', () => {
    const breakdown = {
      appearance: 1.9,
      goals: 2.0,
      assists: 0.9,
      clean_sheet: 0.2,
      bonus: 1.8,
      goals_conceded: 0,
      saves: 0,
      defensive_contribution: 0,
      cards: 0,
      penalties: 0,
    }
    // Ratio = 80/60 = 1.333...
    // appearance scales to 2.53 -> capped to 2
    // bonus scales to 2.4 -> capped to 2.4 (below 3)
    const scaled = scalePoints(6.8, 60, 80, breakdown, 1)
    expect(scaled).toBeCloseTo(8.13, 2)
  })

  it('caps clean sheet and defensive contribution by position', () => {
    const breakdown = {
      appearance: 1.8,
      goals: 0,
      assists: 0,
      clean_sheet: 0.9,
      bonus: 0.5,
      goals_conceded: 0,
      saves: 0,
      defensive_contribution: 1.7,
      cards: 0,
      penalties: 0,
    }
    // Ratio = 100/60 ~= 1.667
    // clean_sheet scales to 1.5 -> cap to 1 for MID
    // defensive contribution scales to 2.83 -> cap to 2
    const scaled = scalePoints(4.9, 60, 100, breakdown, 1, 3)
    expect(scaled).toBeCloseTo(6.83, 2)
  })
})
