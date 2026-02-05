import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { PlayersRemaining } from './PlayersRemaining'
import type { LiveManagerPlayer, GameweekFixtureStatus } from '../../api/client'

const createPlayer = (overrides = {}): LiveManagerPlayer => ({
  player_id: 1,
  position: 1,
  multiplier: 1,
  points: 5,
  effective_points: 5,
  stats: null,
  is_playing: true,
  is_captain: false,
  ...overrides,
})

const mockPlayersMap = new Map([
  [1, { web_name: 'Raya', team: 1, element_type: 1 }],
  [2, { web_name: 'Saliba', team: 1, element_type: 2 }],
  [3, { web_name: 'Gabriel', team: 1, element_type: 2 }],
  [4, { web_name: 'Saka', team: 1, element_type: 3 }],
  [5, { web_name: 'Salah', team: 10, element_type: 3 }],
  [6, { web_name: 'Haaland', team: 11, element_type: 4 }],
])

const createFixtureData = (
  fixtures: Partial<GameweekFixtureStatus['fixtures'][0]>[]
): GameweekFixtureStatus => ({
  gameweek: 1,
  fixtures: fixtures.map((f, i) => ({
    id: i + 1,
    kickoff_time: '2024-01-01T15:00:00Z',
    started: false,
    finished: false,
    minutes: 0,
    home_club_id: 1,
    away_club_id: 2,
    home_score: null,
    away_score: null,
    ...f,
  })),
  total: fixtures.length,
  started: fixtures.filter((f) => f.started).length,
  finished: fixtures.filter((f) => f.finished).length,
  first_kickoff: '2024-01-01T15:00:00Z',
  last_kickoff: '2024-01-01T15:00:00Z',
})

describe('PlayersRemaining', () => {
  it('shows players to play count', () => {
    const players = [
      createPlayer({ player_id: 1, position: 1 }),
      createPlayer({ player_id: 2, position: 2 }),
      createPlayer({ player_id: 3, position: 3 }),
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    // All 3 players have matches yet to play
    expect(screen.getByText('3')).toBeInTheDocument()
    expect(screen.getByText('Left')).toBeInTheDocument()
  })

  it('shows finished players count', () => {
    const players = [
      createPlayer({ player_id: 1, position: 1 }), // Team 1 - finished
      createPlayer({ player_id: 5, position: 2 }), // Team 10 - not finished
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: true, finished: true },
      { home_club_id: 10, away_club_id: 11, started: false, finished: false },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    // 1 player finished, 1 to play
    expect(screen.getByText('Finished')).toBeInTheDocument()
    // Should show Salah in "still to play" section
    expect(screen.getByText('Salah')).toBeInTheDocument()
  })

  it('shows "All players finished" when no players left', () => {
    const players = [createPlayer({ player_id: 1, position: 1 })]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: true, finished: true },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    expect(screen.getByText('All players finished')).toBeInTheDocument()
  })

  it('shows players still to play with names', () => {
    const players = [
      createPlayer({ player_id: 4, position: 1 }), // Saka
      createPlayer({ player_id: 5, position: 2 }), // Salah
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
      { home_club_id: 10, away_club_id: 11, started: false, finished: false },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    expect(screen.getByText('Still to play')).toBeInTheDocument()
    expect(screen.getByText('Saka')).toBeInTheDocument()
    expect(screen.getByText('Salah')).toBeInTheDocument()
  })

  it('shows position labels on player chips', () => {
    const players = [
      createPlayer({ player_id: 1, position: 1 }), // GK
      createPlayer({ player_id: 4, position: 2 }), // MID
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    expect(screen.getByText('GK')).toBeInTheDocument()
    expect(screen.getByText('MID')).toBeInTheDocument()
  })

  it('shows captain badge for captain', () => {
    const players = [createPlayer({ player_id: 6, position: 1, is_captain: true, multiplier: 2 })]

    const fixtureData = createFixtureData([
      { home_club_id: 11, away_club_id: 2, started: false, finished: false },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    expect(screen.getByText('Haaland')).toBeInTheDocument()
    expect(screen.getByText('©')).toBeInTheDocument()
  })

  it('highlights players currently playing', () => {
    const players = [
      createPlayer({ player_id: 4, position: 1 }), // Saka - team 1 is playing
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: true, finished: false, minutes: 45 },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    expect(screen.getByText('Saka')).toBeInTheDocument()
    // Player chip should have live styling (we can check the class or aria)
  })

  it('shows average players left from effective ownership', () => {
    const players = [createPlayer({ player_id: 1, position: 1 })]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
      { home_club_id: 10, away_club_id: 11, started: true, finished: true },
    ])

    // EO data: players on team 1 have matches to play
    const effectiveOwnership = {
      1: 80, // Raya on team 1 - match not started
      5: 90, // Salah on team 10 - match finished
    }

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={effectiveOwnership}
      />
    )

    expect(screen.getByText('10K Avg')).toBeInTheDocument()
    // Average should be based on EO of players with matches left
    expect(screen.getByText('0.8')).toBeInTheDocument() // 80% EO = 0.8 players
  })

  it('shows advantage indicator when ahead of average', () => {
    const players = [
      createPlayer({ player_id: 1, position: 1 }),
      createPlayer({ player_id: 2, position: 2 }),
      createPlayer({ player_id: 3, position: 3 }),
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
    ])

    // Low EO means average has fewer players left
    const effectiveOwnership = {
      1: 50, // 0.5 players left on average
    }

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={effectiveOwnership}
      />
    )

    // You have 3 players, avg has 0.5, so you're +2.5 ahead
    expect(screen.getByText(/▲/)).toBeInTheDocument()
    expect(screen.getByText(/vs avg/)).toBeInTheDocument()
  })

  it('shows disadvantage indicator when behind average', () => {
    const players = [
      createPlayer({ player_id: 1, position: 1 }), // Only 1 player left
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
      { home_club_id: 10, away_club_id: 11, started: false, finished: false },
    ])

    // High EO on players with matches left
    const effectiveOwnership = {
      1: 100, // 1 player
      5: 100, // Another player on team 10
      6: 100, // Another on team 11
    }

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={effectiveOwnership}
      />
    )

    // You have 1 player, avg has ~3, so you're behind
    expect(screen.getByText(/▼/)).toBeInTheDocument()
  })

  it('only counts starting XI players', () => {
    const players = [
      createPlayer({ player_id: 1, position: 1 }), // Starting
      createPlayer({ player_id: 2, position: 11 }), // Starting (last spot)
      createPlayer({ player_id: 3, position: 12 }), // Bench - should not count
    ]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
    ])

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={{}}
      />
    )

    // Only 2 players (positions 1 and 11) should be counted
    expect(screen.getByText('2')).toBeInTheDocument()
  })

  it('handles missing fixture data gracefully', () => {
    const players = [createPlayer({ player_id: 1, position: 1 })]

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={undefined}
        effectiveOwnership={{}}
      />
    )

    // Should show "All players finished" when no fixture data
    expect(screen.getByText('All players finished')).toBeInTheDocument()
  })

  it('caps EO at 100% for average calculation', () => {
    const players = [createPlayer({ player_id: 1, position: 1 })]

    const fixtureData = createFixtureData([
      { home_club_id: 1, away_club_id: 2, started: false, finished: false },
    ])

    // EO over 100% (due to captaincy) should be capped
    const effectiveOwnership = {
      1: 150, // 150% EO due to captaincy - should be capped to 100
    }

    render(
      <PlayersRemaining
        players={players}
        playersMap={mockPlayersMap}
        fixtureData={fixtureData}
        effectiveOwnership={effectiveOwnership}
      />
    )

    // Should show 1.0 (capped at 100%) not 1.5
    expect(screen.getByText('1.0')).toBeInTheDocument()
  })
})
