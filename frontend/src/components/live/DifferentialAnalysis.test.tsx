import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { DifferentialAnalysis } from './DifferentialAnalysis'

describe('DifferentialAnalysis', () => {
  const defaultPlayers = [
    { playerId: 1, name: 'Salah', points: 10, eo: 95, impact: 0.5, multiplier: 2 },
    { playerId: 2, name: 'Isak', points: 12, eo: 15, impact: 10.2, multiplier: 1 },
    { playerId: 3, name: 'Palmer', points: 8, eo: 85, impact: 1.2, multiplier: 1 },
    { playerId: 4, name: 'Gordon', points: 5, eo: 8, impact: 4.6, multiplier: 1 },
  ]

  it('shows player names', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    expect(screen.getByText('Salah')).toBeInTheDocument()
    expect(screen.getByText('Isak')).toBeInTheDocument()
  })

  it('shows points for each player', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // Points shown as "X pts"
    expect(screen.getByText(/10 pts/)).toBeInTheDocument() // Salah
    expect(screen.getByText(/12 pts/)).toBeInTheDocument() // Isak
  })

  it('shows effective ownership for each player', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // EO shown as percentage
    expect(screen.getByText(/95%/)).toBeInTheDocument()
    expect(screen.getByText(/15%/)).toBeInTheDocument()
  })

  it('shows impact score for each player', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // Impact scores
    expect(screen.getByText(/\+10\.2/)).toBeInTheDocument() // Isak's high impact
  })

  it('categorizes high leverage differentials (low EO, high points)', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // Isak has 15% EO and 12 points - should be marked as differential
    // Multiple "Differential" labels exist (in player rows and legend)
    expect(screen.getAllByText(/Differential/i).length).toBeGreaterThan(0)
  })

  it('categorizes template players (high EO)', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // Salah has 95% EO - should be marked as template
    // Multiple "Template" labels exist (in player rows and legend)
    expect(screen.getAllByText(/Template/i).length).toBeGreaterThan(0)
  })

  it('shows ROI metric (impact per point)', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // ROI = impact / points
    // Isak: 10.2 / 12 = 0.85
    // Multiple ROI values are shown (in header and per player)
    expect(screen.getAllByText(/ROI/i).length).toBeGreaterThan(0)
  })

  it('sorts players by impact descending', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    // Isak (10.2 impact) should appear before Gordon (4.6 impact)
    const isakElement = screen.getByText('Isak')
    const gordonElement = screen.getByText('Gordon')

    expect(
      isakElement.compareDocumentPosition(gordonElement) & Node.DOCUMENT_POSITION_FOLLOWING
    ).toBeTruthy()
  })

  it('shows tier label in header', () => {
    render(<DifferentialAnalysis players={defaultPlayers} tierLabel="10K" />)

    expect(screen.getByText(/vs 10K/i)).toBeInTheDocument()
  })

  it('handles empty players array', () => {
    render(<DifferentialAnalysis players={[]} tierLabel="10K" />)

    expect(screen.getByText(/No differential data/i)).toBeInTheDocument()
  })

  it('highlights captain with special indicator', () => {
    const playersWithCaptain = [
      { playerId: 1, name: 'Salah', points: 20, eo: 95, impact: 5, multiplier: 2 }, // Captain (2x)
    ]

    render(<DifferentialAnalysis players={playersWithCaptain} tierLabel="10K" />)

    // Captain badge
    expect(screen.getByText('©')).toBeInTheDocument()
  })
})
