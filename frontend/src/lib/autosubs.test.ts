import { describe, it, expect } from 'vitest'
import { applyAutoSubs, type PlayerInfo } from './autosubs'
import type { LiveManagerPlayer, GameweekFixtureStatus } from '../api/client'

function makePlayer(
  id: number,
  position: number,
  pts: number,
  minutes: number,
  overrides?: Partial<LiveManagerPlayer>
): LiveManagerPlayer {
  return {
    player_id: id,
    position,
    multiplier: position <= 11 ? 1 : 0,
    points: pts,
    effective_points: position <= 11 ? pts : 0,
    stats: {
      minutes,
      goals_scored: 0,
      assists: 0,
      clean_sheets: 0,
      goals_conceded: 0,
      own_goals: 0,
      penalties_saved: 0,
      penalties_missed: 0,
      yellow_cards: 0,
      red_cards: 0,
      saves: 0,
      bonus: 0,
      total_points: pts,
    },
    is_playing: minutes > 0,
    is_captain: false,
    ...overrides,
  }
}

function makeFixtureData(
  teams: Array<{ home: number; away: number; finished: boolean }>
): GameweekFixtureStatus {
  return {
    gameweek: 1,
    fixtures: teams.map((t, i) => ({
      id: i + 1,
      kickoff_time: '2025-01-01T15:00:00Z',
      started: true,
      finished: t.finished,
      minutes: t.finished ? 90 : 45,
      home_club_id: t.home,
      away_club_id: t.away,
      home_score: 1,
      away_score: 0,
    })),
    total: teams.length,
    started: teams.length,
    finished: teams.filter((t) => t.finished).length,
    first_kickoff: '2025-01-01T15:00:00Z',
    last_kickoff: '2025-01-01T15:00:00Z',
  }
}

// Standard squad info: 2 GK, 5 DEF, 5 MID, 3 FWD
function makePlayersInfo(): Map<number, PlayerInfo> {
  const info = new Map<number, PlayerInfo>()
  info.set(1, { web_name: 'GK1', team: 1, element_type: 1 })
  info.set(2, { web_name: 'DEF1', team: 2, element_type: 2 })
  info.set(3, { web_name: 'DEF2', team: 3, element_type: 2 })
  info.set(4, { web_name: 'DEF3', team: 4, element_type: 2 })
  info.set(5, { web_name: 'DEF4', team: 5, element_type: 2 })
  info.set(6, { web_name: 'MID1', team: 6, element_type: 3 })
  info.set(7, { web_name: 'MID2', team: 7, element_type: 3 })
  info.set(8, { web_name: 'MID3', team: 8, element_type: 3 })
  info.set(9, { web_name: 'MID4', team: 9, element_type: 3 })
  info.set(10, { web_name: 'FWD1', team: 10, element_type: 4 })
  info.set(11, { web_name: 'FWD2', team: 11, element_type: 4 })
  // Bench
  info.set(12, { web_name: 'GK2', team: 12, element_type: 1 })
  info.set(13, { web_name: 'DEF5', team: 13, element_type: 2 })
  info.set(14, { web_name: 'MID5', team: 14, element_type: 3 })
  info.set(15, { web_name: 'FWD3', team: 15, element_type: 4 })
  return info
}

// All fixtures finished
function allFinished(): GameweekFixtureStatus {
  const teams = []
  for (let i = 1; i <= 15; i += 2) {
    teams.push({ home: i, away: i + 1, finished: true })
  }
  return makeFixtureData(teams)
}

describe('applyAutoSubs', () => {
  it('returns original squad when no fixture data', () => {
    const players = [makePlayer(1, 1, 5, 90), makePlayer(2, 2, 3, 90)]
    const result = applyAutoSubs(players, makePlayersInfo(), undefined)
    expect(result.autoSubs).toHaveLength(0)
    expect(result.players).toBe(players) // same reference, no clone
  })

  it('makes no subs when all starters played', () => {
    const players = [
      // Starting XI (positions 1-11)
      makePlayer(1, 1, 5, 90),
      makePlayer(2, 2, 6, 90),
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 2, 90),
      makePlayer(6, 6, 8, 90),
      makePlayer(7, 7, 7, 90),
      makePlayer(8, 8, 5, 90),
      makePlayer(9, 9, 4, 90),
      makePlayer(10, 10, 6, 90),
      makePlayer(11, 11, 3, 90),
      // Bench (positions 12-15)
      makePlayer(12, 12, 2, 90),
      makePlayer(13, 13, 4, 90),
      makePlayer(14, 14, 3, 90),
      makePlayer(15, 15, 1, 90),
    ]

    const result = applyAutoSubs(players, makePlayersInfo(), allFinished())
    expect(result.autoSubs).toHaveLength(0)
  })

  it('subs in first bench player when starter has 0 minutes', () => {
    const players = [
      makePlayer(1, 1, 5, 90),
      makePlayer(2, 2, 6, 90),
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 0, 0), // DEF4 didn't play
      makePlayer(6, 6, 8, 90),
      makePlayer(7, 7, 7, 90),
      makePlayer(8, 8, 5, 90),
      makePlayer(9, 9, 4, 90),
      makePlayer(10, 10, 6, 90),
      makePlayer(11, 11, 3, 90),
      makePlayer(12, 12, 2, 90),
      makePlayer(13, 13, 4, 90), // First outfield bench
      makePlayer(14, 14, 3, 90),
      makePlayer(15, 15, 1, 90),
    ]

    const result = applyAutoSubs(players, makePlayersInfo(), allFinished())
    expect(result.autoSubs).toHaveLength(1)
    expect(result.autoSubs[0]).toEqual({ out: 5, in: 13 })
  })

  it('does not sub when match is not finished', () => {
    const playersInfo = makePlayersInfo()
    const fixtureData = makeFixtureData([
      { home: 5, away: 99, finished: false }, // DEF4's match still playing
      ...Array.from({ length: 7 }, (_, i) => ({
        home: i * 2 + 1,
        away: i * 2 + 2,
        finished: true,
      })).filter((f) => f.home !== 5),
    ])

    const players = [
      makePlayer(1, 1, 5, 90),
      makePlayer(2, 2, 6, 90),
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 0, 0), // 0 mins but match not finished
      makePlayer(6, 6, 8, 90),
      makePlayer(7, 7, 7, 90),
      makePlayer(8, 8, 5, 90),
      makePlayer(9, 9, 4, 90),
      makePlayer(10, 10, 6, 90),
      makePlayer(11, 11, 3, 90),
      makePlayer(12, 12, 2, 90),
      makePlayer(13, 13, 4, 90),
      makePlayer(14, 14, 3, 90),
      makePlayer(15, 15, 1, 90),
    ]

    const result = applyAutoSubs(players, playersInfo, fixtureData)
    expect(result.autoSubs).toHaveLength(0)
  })

  it('only subs GK for GK', () => {
    const players = [
      makePlayer(1, 1, 0, 0), // GK didn't play
      makePlayer(2, 2, 6, 90),
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 2, 90),
      makePlayer(6, 6, 8, 90),
      makePlayer(7, 7, 7, 90),
      makePlayer(8, 8, 5, 90),
      makePlayer(9, 9, 4, 90),
      makePlayer(10, 10, 6, 90),
      makePlayer(11, 11, 3, 90),
      makePlayer(12, 12, 2, 90), // Bench GK
      makePlayer(13, 13, 4, 90),
      makePlayer(14, 14, 3, 90),
      makePlayer(15, 15, 1, 90),
    ]

    const result = applyAutoSubs(players, makePlayersInfo(), allFinished())
    expect(result.autoSubs).toHaveLength(1)
    // GK (id:1) should be replaced by bench GK (id:12), not DEF5 (id:13)
    expect(result.autoSubs[0]).toEqual({ out: 1, in: 12 })
  })

  it('skips bench player who also did not play', () => {
    const players = [
      makePlayer(1, 1, 5, 90),
      makePlayer(2, 2, 6, 90),
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 0, 0), // DEF4 didn't play
      makePlayer(6, 6, 8, 90),
      makePlayer(7, 7, 7, 90),
      makePlayer(8, 8, 5, 90),
      makePlayer(9, 9, 4, 90),
      makePlayer(10, 10, 6, 90),
      makePlayer(11, 11, 3, 90),
      makePlayer(12, 12, 2, 90),
      makePlayer(13, 13, 0, 0), // First bench also didn't play
      makePlayer(14, 14, 3, 90), // Second bench should be used
      makePlayer(15, 15, 1, 90),
    ]

    const result = applyAutoSubs(players, makePlayersInfo(), allFinished())
    expect(result.autoSubs).toHaveLength(1)
    expect(result.autoSubs[0]).toEqual({ out: 5, in: 14 })
  })

  it('sets correct multiplier and effective_points after sub', () => {
    const players = [
      makePlayer(1, 1, 5, 90),
      makePlayer(2, 2, 6, 90),
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 0, 0), // DEF4 didn't play
      makePlayer(6, 6, 8, 90),
      makePlayer(7, 7, 7, 90),
      makePlayer(8, 8, 5, 90),
      makePlayer(9, 9, 4, 90),
      makePlayer(10, 10, 6, 90),
      makePlayer(11, 11, 3, 90),
      makePlayer(12, 12, 2, 90),
      makePlayer(13, 13, 4, 90),
      makePlayer(14, 14, 3, 90),
      makePlayer(15, 15, 1, 90),
    ]

    const result = applyAutoSubs(players, makePlayersInfo(), allFinished())

    // The subbed-out player should have multiplier 0
    const subbedOut = result.players.find((p) => p.player_id === 5)!
    expect(subbedOut.multiplier).toBe(0)
    expect(subbedOut.effective_points).toBe(0)

    // The subbed-in player should have multiplier 1 and effective_points = points
    const subbedIn = result.players.find((p) => p.player_id === 13)!
    expect(subbedIn.multiplier).toBe(1)
    expect(subbedIn.effective_points).toBe(4)
  })

  it('respects formation constraints (will not drop below 3 DEF)', () => {
    // 3-4-3 formation: 1 GK, 3 DEF, 4 MID, 3 FWD starting
    // DEF1 doesn't play. Bench has FWD first, then MID.
    // FWD can't come in (would leave only 2 DEF).
    // MID can come in (2 DEF still on pitch + no max MID breach since 4+1=5).
    const playersInfo = new Map<number, PlayerInfo>()
    playersInfo.set(1, { web_name: 'GK1', team: 1, element_type: 1 })
    playersInfo.set(2, { web_name: 'DEF1', team: 2, element_type: 2 })
    playersInfo.set(3, { web_name: 'DEF2', team: 3, element_type: 2 })
    playersInfo.set(4, { web_name: 'DEF3', team: 4, element_type: 2 })
    playersInfo.set(5, { web_name: 'MID1', team: 5, element_type: 3 })
    playersInfo.set(6, { web_name: 'MID2', team: 6, element_type: 3 })
    playersInfo.set(7, { web_name: 'MID3', team: 7, element_type: 3 })
    playersInfo.set(8, { web_name: 'MID4', team: 8, element_type: 3 })
    playersInfo.set(9, { web_name: 'FWD1', team: 9, element_type: 4 })
    playersInfo.set(10, { web_name: 'FWD2', team: 10, element_type: 4 })
    playersInfo.set(11, { web_name: 'FWD3', team: 11, element_type: 4 })
    // Bench
    playersInfo.set(12, { web_name: 'GK2', team: 12, element_type: 1 })
    playersInfo.set(13, { web_name: 'FWD4', team: 13, element_type: 4 }) // FWD first on bench
    playersInfo.set(14, { web_name: 'MID5', team: 14, element_type: 3 }) // MID second on bench
    playersInfo.set(15, { web_name: 'DEF4', team: 15, element_type: 2 })

    const players = [
      makePlayer(1, 1, 5, 90),
      makePlayer(2, 2, 0, 0), // DEF1 didn't play
      makePlayer(3, 3, 4, 90),
      makePlayer(4, 4, 3, 90),
      makePlayer(5, 5, 8, 90),
      makePlayer(6, 6, 7, 90),
      makePlayer(7, 7, 5, 90),
      makePlayer(8, 8, 4, 90),
      makePlayer(9, 9, 6, 90),
      makePlayer(10, 10, 3, 90),
      makePlayer(11, 11, 2, 90),
      makePlayer(12, 12, 2, 90),
      makePlayer(13, 13, 4, 90), // FWD4 — would give 4 FWD but only 2 DEF: blocked
      makePlayer(14, 14, 3, 90), // MID5 — gives 5 MID, 2 DEF: 2 DEF < 3: also blocked
      makePlayer(15, 15, 1, 90), // DEF4 — gives 3 DEF, 4 MID, 3 FWD: valid
    ]

    const result = applyAutoSubs(players, playersInfo, allFinished())
    // FWD4 (pos 13) blocked: would drop to 2 DEF
    // MID5 (pos 14) blocked: would also drop to 2 DEF
    // DEF4 (pos 15) valid: keeps 3 DEF
    expect(result.autoSubs).toHaveLength(1)
    expect(result.autoSubs[0]).toEqual({ out: 2, in: 15 })
  })
})
