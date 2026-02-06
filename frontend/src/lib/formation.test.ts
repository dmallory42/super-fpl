import { describe, it, expect } from 'vitest'
import { buildFormation, type SquadPlayer } from './formation'

function makePlayer(
  id: number,
  position: number,
  pts: number,
  overrides?: Partial<SquadPlayer>
): SquadPlayer {
  return {
    player_id: id,
    web_name: `Player ${id}`,
    element_type: position,
    team: 1,
    predicted_points: pts,
    ...overrides,
  }
}

/** Standard 15-player squad: 2 GK, 5 DEF, 5 MID, 3 FWD */
function makeStandardSquad(): SquadPlayer[] {
  return [
    makePlayer(1, 1, 3), // GK1
    makePlayer(2, 1, 2), // GK2
    makePlayer(3, 2, 5), // DEF1
    makePlayer(4, 2, 4.5), // DEF2
    makePlayer(5, 2, 4), // DEF3
    makePlayer(6, 2, 3), // DEF4
    makePlayer(7, 2, 2.5), // DEF5
    makePlayer(8, 3, 7), // MID1
    makePlayer(9, 3, 6), // MID2
    makePlayer(10, 3, 5.5), // MID3
    makePlayer(11, 3, 4), // MID4
    makePlayer(12, 3, 3), // MID5
    makePlayer(13, 4, 8), // FWD1 (highest pts)
    makePlayer(14, 4, 6), // FWD2
    makePlayer(15, 4, 1), // FWD3
  ]
}

describe('buildFormation', () => {
  it('returns 15 players with positions 1-15', () => {
    const result = buildFormation(makeStandardSquad())
    expect(result).toHaveLength(15)
    const positions = result.map((p) => p.position).sort((a, b) => a - b)
    expect(positions).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15])
  })

  it('starts exactly 11 players (positions 1-11)', () => {
    const result = buildFormation(makeStandardSquad())
    const starters = result.filter((p) => p.position <= 11)
    expect(starters).toHaveLength(11)
  })

  it('benches exactly 4 players (positions 12-15)', () => {
    const result = buildFormation(makeStandardSquad())
    const bench = result.filter((p) => p.position > 11)
    expect(bench).toHaveLength(4)
  })

  it('starts exactly 1 GK', () => {
    const result = buildFormation(makeStandardSquad())
    const startingGks = result.filter((p) => p.position <= 11 && p.element_type === 1)
    expect(startingGks).toHaveLength(1)
  })

  it('starts at least 3 DEF', () => {
    const result = buildFormation(makeStandardSquad())
    const startingDefs = result.filter((p) => p.position <= 11 && p.element_type === 2)
    expect(startingDefs.length).toBeGreaterThanOrEqual(3)
  })

  it('starts at least 1 FWD', () => {
    const result = buildFormation(makeStandardSquad())
    const startingFwds = result.filter((p) => p.position <= 11 && p.element_type === 4)
    expect(startingFwds.length).toBeGreaterThanOrEqual(1)
  })

  it('picks the highest-predicted starter as captain', () => {
    const result = buildFormation(makeStandardSquad())
    const captain = result.find((p) => p.is_captain)
    expect(captain).toBeDefined()
    // Player 13 (FWD1) has 8pts, highest overall
    expect(captain!.player_id).toBe(13)
  })

  it('captain has multiplier 2, non-captains have multiplier 1', () => {
    const result = buildFormation(makeStandardSquad())
    const captain = result.find((p) => p.is_captain)!
    expect(captain.multiplier).toBe(2)
    for (const p of result) {
      if (!p.is_captain) expect(p.multiplier).toBe(1)
    }
  })

  it('places GK first on the bench', () => {
    const result = buildFormation(makeStandardSquad())
    const bench = result.filter((p) => p.position > 11)
    expect(bench[0].element_type).toBe(1)
  })

  it('prefers higher-predicted players in starting XI', () => {
    const result = buildFormation(makeStandardSquad())
    const startingMids = result
      .filter((p) => p.position <= 11 && p.element_type === 3)
      .map((p) => p.player_id)
    // The best mids (8, 9, 10) should start over weaker ones (11, 12)
    expect(startingMids).toContain(8)
    expect(startingMids).toContain(9)
    expect(startingMids).toContain(10)
  })

  it('handles a squad where mids dominate predictions', () => {
    // Give mids very high points to test formation constraint (max 5 MID)
    const squad = makeStandardSquad()
    for (const p of squad) {
      if (p.element_type === 3) p.predicted_points = 10
    }
    const result = buildFormation(squad)
    const startingMids = result.filter((p) => p.position <= 11 && p.element_type === 3)
    expect(startingMids.length).toBeLessThanOrEqual(5)
    // Still need at least 3 DEF and 1 FWD and 1 GK = 5, so max 6 mids but capped at 5
    const startingDefs = result.filter((p) => p.position <= 11 && p.element_type === 2)
    expect(startingDefs.length).toBeGreaterThanOrEqual(3)
  })

  it('returns empty array for empty squad', () => {
    expect(buildFormation([])).toEqual([])
  })

  it('starters are sorted by element_type (GK, DEF, MID, FWD)', () => {
    const result = buildFormation(makeStandardSquad())
    const starters = result.filter((p) => p.position <= 11)
    const types = starters.map((p) => p.element_type)
    // Should be non-decreasing
    for (let i = 1; i < types.length; i++) {
      expect(types[i]).toBeGreaterThanOrEqual(types[i - 1])
    }
  })
})
