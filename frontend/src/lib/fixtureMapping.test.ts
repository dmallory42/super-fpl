import { describe, it, expect } from 'vitest'
import type { GameweekFixtureStatus } from '../api/client'
import {
  getTeamFixtureStatus,
  resolveFixtureForTeam,
  toBoolFlag,
  getFixturesForTeam,
} from './fixtureMapping'

function makeFixtureData(fixtures: GameweekFixtureStatus['fixtures']): GameweekFixtureStatus {
  return {
    gameweek: 24,
    fixtures,
    total: fixtures.length,
    started: fixtures.filter((f) => toBoolFlag(f.started)).length,
    finished: fixtures.filter((f) => toBoolFlag(f.finished)).length,
    first_kickoff: fixtures[0]?.kickoff_time ?? '',
    last_kickoff: fixtures[fixtures.length - 1]?.kickoff_time ?? '',
  }
}

describe('fixtureMapping', () => {
  it('normalizes boolean and numeric status flags', () => {
    expect(toBoolFlag(true)).toBe(true)
    expect(toBoolFlag(1)).toBe(true)
    expect(toBoolFlag(false)).toBe(false)
    expect(toBoolFlag(0)).toBe(false)
    expect(toBoolFlag(undefined)).toBe(false)
  })

  it('returns playing when a team has one active DGW fixture and one upcoming', () => {
    const data = makeFixtureData([
      {
        id: 100,
        kickoff_time: '2026-02-13T12:00:00Z',
        home_club_id: 1,
        away_club_id: 2,
        started: 1,
        finished: 0,
        minutes: 55,
        home_score: 1,
        away_score: 0,
      },
      {
        id: 101,
        kickoff_time: '2026-02-13T18:00:00Z',
        home_club_id: 3,
        away_club_id: 1,
        started: 0,
        finished: 0,
        minutes: 0,
        home_score: null,
        away_score: null,
      },
    ])

    expect(getTeamFixtureStatus(data, 1)).toBe('playing')
    expect(getFixturesForTeam(data, 1)).toHaveLength(2)
  })

  it('resolves DGW fixture deterministically using explain fixture ids', () => {
    const data = makeFixtureData([
      {
        id: 100,
        kickoff_time: '2026-02-13T12:00:00Z',
        home_club_id: 1,
        away_club_id: 2,
        started: true,
        finished: false,
        minutes: 55,
        home_score: 1,
        away_score: 0,
      },
      {
        id: 101,
        kickoff_time: '2026-02-13T15:00:00Z',
        home_club_id: 3,
        away_club_id: 1,
        started: true,
        finished: false,
        minutes: 12,
        home_score: 0,
        away_score: 0,
      },
    ])

    const resolved = resolveFixtureForTeam(data, 1, [101])
    expect(resolved?.id).toBe(101)
  })
})
