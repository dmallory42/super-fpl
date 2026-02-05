import { describe, it, expect } from 'vitest'
import { render, screen, fireEvent } from '../../test/utils'
import { FixtureScores } from './FixtureScores'
import type { GameweekFixtureStatus, LiveElement, BonusPrediction } from '../../api/client'

const createFixture = (overrides = {}) => ({
  id: 1,
  kickoff_time: '2024-01-01T15:00:00Z',
  started: true,
  finished: false,
  minutes: 45,
  home_club_id: 1,
  away_club_id: 2,
  home_score: 1,
  away_score: 0,
  ...overrides,
})

const createLiveElement = (overrides = {}): LiveElement => ({
  id: 1,
  stats: {
    minutes: 90,
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
    bps: 0,
    total_points: 2,
  },
  ...overrides,
})

const mockTeamsMap = new Map([
  [1, 'Arsenal'],
  [2, 'Chelsea'],
  [3, 'Liverpool'],
])

const mockPlayersMap = new Map([
  [1, { web_name: 'Saka', team: 1, element_type: 3 }],
  [2, { web_name: 'Palmer', team: 2, element_type: 3 }],
  [3, { web_name: 'Salah', team: 3, element_type: 3 }],
  [4, { web_name: 'Raya', team: 1, element_type: 1 }],
  [5, { web_name: 'Gabriel', team: 1, element_type: 2 }],
])

describe('FixtureScores', () => {
  it('renders empty state when no fixtures', () => {
    render(
      <FixtureScores
        fixtureData={undefined}
        teamsMap={mockTeamsMap}
        liveElements={[]}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    expect(screen.getByText('No fixtures available')).toBeInTheDocument()
  })

  it('renders fixture with team names and score', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture({ home_score: 2, away_score: 1 })],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={[]}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    expect(screen.getByText('Arsenal')).toBeInTheDocument()
    expect(screen.getByText('Chelsea')).toBeInTheDocument()
    expect(screen.getByText('2')).toBeInTheDocument()
    expect(screen.getByText('1')).toBeInTheDocument()
  })

  it('shows kickoff time for upcoming fixtures', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [
        createFixture({ started: false, finished: false, home_score: null, away_score: null }),
      ],
      total: 1,
      started: 0,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={[]}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    // Should show time instead of score
    expect(screen.getByText('15:00')).toBeInTheDocument()
  })

  it('shows FT for finished fixtures', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture({ started: true, finished: true, minutes: 90 })],
      total: 1,
      started: 1,
      finished: 1,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={[]}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    expect(screen.getByText('FT')).toBeInTheDocument()
  })

  it('shows minutes for live fixtures', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture({ started: true, finished: false, minutes: 67 })],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={[]}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    expect(screen.getByText("67'")).toBeInTheDocument()
  })

  it('expands fixture details on click when there are events', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture()],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 1, stats: { ...createLiveElement().stats, goals_scored: 1 } }),
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    // Click to expand
    fireEvent.click(screen.getByRole('button'))

    // Should show goal scorer
    expect(screen.getByText('Saka')).toBeInTheDocument()
  })

  it('shows goal emoji for scorers', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture()],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 1, stats: { ...createLiveElement().stats, goals_scored: 2 } }),
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    fireEvent.click(screen.getByRole('button'))

    // Should show two goal emojis
    expect(screen.getByText('⚽⚽')).toBeInTheDocument()
  })

  it('shows assist emoji for assisters', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture()],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 1, stats: { ...createLiveElement().stats, assists: 1 } }),
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    fireEvent.click(screen.getByRole('button'))

    expect(screen.getByText('🅰️')).toBeInTheDocument()
  })

  it('shows bonus predictions with medal styling', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture({ id: 100 })],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 1, stats: { ...createLiveElement().stats, goals_scored: 1 } }),
    ]

    const bonusPredictions: BonusPrediction[] = [
      { player_id: 1, bps: 35, predicted_bonus: 3, fixture_id: 100 },
      { player_id: 2, bps: 30, predicted_bonus: 2, fixture_id: 100 },
      { player_id: 3, bps: 25, predicted_bonus: 1, fixture_id: 100 },
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={bonusPredictions}
      />
    )

    fireEvent.click(screen.getByRole('button'))

    expect(screen.getByText('Bonus Points')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
    expect(screen.getByText('(35)')).toBeInTheDocument()
  })

  it('handles tied bonus points correctly', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture({ id: 100 })],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 1, stats: { ...createLiveElement().stats, goals_scored: 1 } }),
    ]

    // Two players tied on 3 bonus
    const bonusPredictions: BonusPrediction[] = [
      { player_id: 1, bps: 35, predicted_bonus: 3, fixture_id: 100 },
      { player_id: 2, bps: 35, predicted_bonus: 3, fixture_id: 100 },
      { player_id: 3, bps: 25, predicted_bonus: 1, fixture_id: 100 },
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={bonusPredictions}
      />
    )

    fireEvent.click(screen.getByRole('button'))

    // Both players with 3 bonus should be shown (Saka may appear twice - events + bonus)
    expect(screen.getAllByText('Saka').length).toBeGreaterThanOrEqual(1)
    expect(screen.getAllByText('Palmer').length).toBeGreaterThanOrEqual(1)
    // Two "3" bonus badges should exist (both have predicted_bonus: 3)
    expect(screen.getAllByText('3').length).toBe(2)
  })

  it('shows clean sheet emoji for defenders', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture()],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 5, stats: { ...createLiveElement().stats, clean_sheets: 1 } }),
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    fireEvent.click(screen.getByRole('button'))

    expect(screen.getByText('Gabriel')).toBeInTheDocument()
    expect(screen.getByTitle('Clean sheet')).toBeInTheDocument()
  })

  it('shows saves emoji for goalkeepers with 3+ saves', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [createFixture()],
      total: 1,
      started: 1,
      finished: 0,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    const liveElements: LiveElement[] = [
      createLiveElement({ id: 4, stats: { ...createLiveElement().stats, saves: 5 } }),
    ]

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={liveElements}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    fireEvent.click(screen.getByRole('button'))

    expect(screen.getByText('Raya')).toBeInTheDocument()
    expect(screen.getByTitle('5 saves')).toBeInTheDocument()
  })

  it('sorts live fixtures first', () => {
    const fixtureData: GameweekFixtureStatus = {
      gameweek: 1,
      fixtures: [
        createFixture({ id: 1, started: true, finished: true, home_club_id: 1, away_club_id: 2 }),
        createFixture({
          id: 2,
          started: true,
          finished: false,
          home_club_id: 3,
          away_club_id: 1,
          minutes: 45,
        }),
      ],
      total: 2,
      started: 2,
      finished: 1,
      first_kickoff: '2024-01-01T15:00:00Z',
      last_kickoff: '2024-01-01T15:00:00Z',
    }

    render(
      <FixtureScores
        fixtureData={fixtureData}
        teamsMap={mockTeamsMap}
        liveElements={[]}
        playersMap={mockPlayersMap}
        bonusPredictions={[]}
      />
    )

    const buttons = screen.getAllByRole('button')
    // First fixture should be the live one (Liverpool vs Arsenal)
    expect(buttons[0]).toHaveTextContent('Liverpool')
  })
})
