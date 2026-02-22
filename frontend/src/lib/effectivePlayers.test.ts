import { describe, expect, it } from 'vitest'
import type { GameweekFixtureStatus, LiveManagerPlayer } from '../api/client'
import {
  calculateTierEffectivePlayersPlayed,
  calculateUserEffectivePlayersPlayed,
} from './effectivePlayers'

function createPlayer(overrides: Partial<LiveManagerPlayer> = {}): LiveManagerPlayer {
  return {
    player_id: 1,
    position: 1,
    multiplier: 1,
    points: 0,
    effective_points: 0,
    stats: null,
    is_playing: false,
    is_captain: false,
    ...overrides,
  }
}

const playersMap = new Map<number, { web_name: string; team: number; element_type: number }>([
  [1, { web_name: 'Finished Team Player', team: 1, element_type: 3 }],
  [2, { web_name: 'Live Team Captain', team: 2, element_type: 3 }],
  [3, { web_name: 'Upcoming Team Player', team: 3, element_type: 3 }],
])

const fixtureData: GameweekFixtureStatus = {
  gameweek: 27,
  fixtures: [
    {
      id: 101,
      kickoff_time: '2026-02-21T15:00:00Z',
      started: true,
      finished: true,
      minutes: 90,
      home_club_id: 1,
      away_club_id: 4,
      home_score: 1,
      away_score: 0,
    },
    {
      id: 102,
      kickoff_time: '2026-02-22T15:00:00Z',
      started: true,
      finished: false,
      minutes: 35,
      home_club_id: 2,
      away_club_id: 5,
      home_score: 0,
      away_score: 0,
    },
    {
      id: 103,
      kickoff_time: '2026-02-23T20:00:00Z',
      started: false,
      finished: false,
      minutes: 0,
      home_club_id: 3,
      away_club_id: 6,
      home_score: null,
      away_score: null,
    },
  ],
  total: 3,
  started: 2,
  finished: 1,
  first_kickoff: '2026-02-21T15:00:00Z',
  last_kickoff: '2026-02-23T20:00:00Z',
}

describe('effective players played', () => {
  it('counts captaincy weights for user played and total values', () => {
    const result = calculateUserEffectivePlayersPlayed(
      [
        createPlayer({ player_id: 1, multiplier: 1 }),
        createPlayer({ player_id: 2, multiplier: 2, is_captain: true }),
        createPlayer({ player_id: 3, multiplier: 1 }),
        createPlayer({ player_id: 2, multiplier: 0, position: 12 }),
      ],
      playersMap,
      fixtureData
    )

    expect(result.played).toBe(3)
    expect(result.total).toBe(4)
  })

  it('counts triple captain as 3 players', () => {
    const result = calculateUserEffectivePlayersPlayed(
      [
        createPlayer({ player_id: 1, multiplier: 3, is_captain: true }),
        createPlayer({ player_id: 3, multiplier: 1 }),
      ],
      playersMap,
      fixtureData
    )

    expect(result.played).toBe(3)
    expect(result.total).toBe(4)
  })

  it('calculates tier effective players from EO with 3x cap', () => {
    const result = calculateTierEffectivePlayersPlayed(
      {
        1: 310, // capped to 300
        2: 250,
        3: 80,
      },
      playersMap,
      fixtureData
    )

    expect(result.played).toBeCloseTo(5.5, 5)
    expect(result.total).toBeCloseTo(6.3, 5)
  })
})
