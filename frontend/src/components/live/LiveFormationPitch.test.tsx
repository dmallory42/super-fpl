import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { LiveFormationPitch } from './LiveFormationPitch'
import type { GameweekFixtureStatus, LiveManagerPlayer } from '../../api/client'

const players: LiveManagerPlayer[] = [
  {
    player_id: 1,
    position: 1,
    multiplier: 1,
    points: 6,
    effective_points: 6,
    stats: null,
    is_playing: true,
    is_captain: false,
  },
  {
    player_id: 2,
    position: 2,
    multiplier: 2,
    points: 5,
    effective_points: 10,
    stats: null,
    is_playing: false,
    is_captain: true,
  },
  {
    player_id: 3,
    position: 12,
    multiplier: 1,
    points: 3,
    effective_points: 3,
    stats: null,
    is_playing: false,
    is_captain: false,
  },
]

const playersInfo = new Map<number, { web_name: string; team: number; element_type: number }>([
  [1, { web_name: 'Raya', team: 1, element_type: 1 }],
  [2, { web_name: 'Saka', team: 2, element_type: 3 }],
  [3, { web_name: 'BenchMID', team: 1, element_type: 3 }],
])

const teamsInfo = new Map<number, string>([
  [1, 'ARS'],
  [2, 'CHE'],
])

const fixtureData: GameweekFixtureStatus = {
  current_gameweek: 30,
  is_live: true,
  fixtures: [
    {
      id: 11,
      gameweek: 30,
      home_club_id: 1,
      away_club_id: 3,
      kickoff_time: '2026-02-25T15:00:00Z',
      home_score: 1,
      away_score: 0,
      started: true,
      finished: false,
      minutes: 57,
    },
    {
      id: 12,
      gameweek: 30,
      home_club_id: 2,
      away_club_id: 4,
      kickoff_time: '2026-02-25T12:00:00Z',
      home_score: 2,
      away_score: 1,
      started: true,
      finished: true,
      minutes: 90,
    },
  ],
}

describe('LiveFormationPitch', () => {
  it('renders starters, bench, and live minute status', () => {
    render(
      <LiveFormationPitch
        players={players}
        playersInfo={playersInfo}
        teamsInfo={teamsInfo}
        fixtureData={fixtureData}
      />
    )

    expect(screen.getByText('Raya')).toBeInTheDocument()
    expect(screen.getByText('Saka')).toBeInTheDocument()
    expect(screen.getByText('BenchMID')).toBeInTheDocument()
    expect(screen.getByText('Bench')).toBeInTheDocument()
    expect(screen.getAllByText("57'").length).toBeGreaterThan(0)
  })

  it('handles missing player metadata with safe fallbacks', () => {
    render(
      <LiveFormationPitch
        players={[
          {
            player_id: 999,
            position: 1,
            multiplier: 1,
            points: 0,
            effective_points: 0,
            stats: null,
            is_playing: false,
            is_captain: false,
          },
        ]}
        playersInfo={new Map()}
        teamsInfo={new Map()}
      />
    )

    expect(screen.getByText('P999')).toBeInTheDocument()
    expect(screen.getByText('???')).toBeInTheDocument()
  })
})
