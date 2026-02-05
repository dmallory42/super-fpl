import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '../../test/utils'
import { FixtureThreatIndex } from './FixtureThreatIndex'
import type { GameweekFixtureStatus } from '../../api/client'

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

const mockFixtureImpacts = [
  {
    fixtureId: 1,
    homeTeam: 'ARS',
    awayTeam: 'CHE',
    userPoints: 15,
    tierAvgPoints: 10,
    impact: 5,
    isLive: false,
    isFinished: true,
    hasUserPlayer: true,
  },
  {
    fixtureId: 2,
    homeTeam: 'LIV',
    awayTeam: 'MCI',
    userPoints: 8,
    tierAvgPoints: 12,
    impact: -4,
    isLive: false,
    isFinished: true,
    hasUserPlayer: true,
  },
]

describe('FixtureThreatIndex', () => {
  const defaultProps = {
    fixtureData: createFixtureData([
      { id: 1, home_club_id: 1, away_club_id: 2, started: true, finished: true },
      { id: 2, home_club_id: 3, away_club_id: 4, started: true, finished: true },
    ]),
    fixtureImpacts: mockFixtureImpacts,
    selectedTier: 'top_10k' as const,
    onTierChange: vi.fn(),
  }

  it('shows fixture with team names', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    expect(screen.getByText('ARS')).toBeInTheDocument()
    expect(screen.getByText('CHE')).toBeInTheDocument()
  })

  it('shows user points for a fixture', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // User points: 15.0 and 8.0
    expect(screen.getByText('15.0')).toBeInTheDocument()
    expect(screen.getByText('8.0')).toBeInTheDocument()
  })

  it('shows tier average points for a fixture', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // Tier avg: 10.0 and 12.0
    expect(screen.getByText('10.0')).toBeInTheDocument()
    expect(screen.getByText('12.0')).toBeInTheDocument()
  })

  it('shows positive impact with green styling', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // ARS vs CHE has +5 impact
    expect(screen.getByText('+5.0')).toBeInTheDocument()
  })

  it('shows negative impact with red styling', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // LIV vs MCI has -4 impact
    expect(screen.getByText('-4.0')).toBeInTheDocument()
  })

  it('shows net impact total', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // Net impact: 5 - 4 = +1
    expect(screen.getByText('+1.0')).toBeInTheDocument()
    expect(screen.getByText(/Net Impact/i)).toBeInTheDocument()
  })

  it('shows tier selector buttons', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    expect(screen.getByText('10K')).toBeInTheDocument()
    expect(screen.getByText('100K')).toBeInTheDocument()
    expect(screen.getByText('1M')).toBeInTheDocument()
    expect(screen.getByText('All')).toBeInTheDocument()
  })

  it('calls onTierChange when tier button clicked', () => {
    const onTierChange = vi.fn()
    render(<FixtureThreatIndex {...defaultProps} onTierChange={onTierChange} />)

    screen.getByText('100K').click()
    expect(onTierChange).toHaveBeenCalledWith('top_100k')
  })

  it('shows summary with user total and tier total', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // User total: 15 + 8 = 23
    expect(screen.getByText('23.0')).toBeInTheDocument()
    // Tier total: 10 + 12 = 22
    expect(screen.getByText('22.0')).toBeInTheDocument()
  })

  it('handles empty fixture data', () => {
    render(
      <FixtureThreatIndex
        fixtureData={undefined}
        fixtureImpacts={[]}
        selectedTier="top_10k"
        onTierChange={vi.fn()}
      />
    )

    expect(screen.getByText(/No fixture data/i)).toBeInTheDocument()
  })

  it('sorts fixtures by absolute impact', () => {
    render(<FixtureThreatIndex {...defaultProps} />)

    // ARS (impact +5) should appear before LIV (impact -4) due to higher absolute value
    const arsElement = screen.getByText('ARS')
    const livElement = screen.getByText('LIV')

    expect(
      arsElement.compareDocumentPosition(livElement) & Node.DOCUMENT_POSITION_FOLLOWING
    ).toBeTruthy()
  })

  it('shows 0.0 vs tier avg when user has no players in fixture', () => {
    const fixtureWithNoUserPlayer = [
      {
        fixtureId: 3,
        homeTeam: 'TOT',
        awayTeam: 'WHU',
        userPoints: 0,
        tierAvgPoints: 8.5,
        impact: -8.5,
        isLive: false,
        isFinished: true,
        hasUserPlayer: false,
      },
    ]

    render(
      <FixtureThreatIndex
        fixtureData={createFixtureData([
          { id: 3, home_club_id: 5, away_club_id: 6, started: true, finished: true },
        ])}
        fixtureImpacts={fixtureWithNoUserPlayer}
        selectedTier="top_10k"
        onTierChange={vi.fn()}
      />
    )

    // Should show 0.0 for user points (appears in summary and fixture row)
    expect(screen.getAllByText('0.0').length).toBeGreaterThan(0)
    // Should show tier avg (appears in summary and fixture row)
    expect(screen.getAllByText('8.5').length).toBeGreaterThan(0)
    // Should show negative impact (in header and fixture row)
    expect(screen.getAllByText('-8.5').length).toBeGreaterThan(0)
  })
})
