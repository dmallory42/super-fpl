import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { VarianceAnalysis } from './VarianceAnalysis'

describe('VarianceAnalysis', () => {
  const defaultProps = {
    players: [
      { playerId: 1, name: 'Salah', predicted: 6, actual: 10 },
      { playerId: 2, name: 'Haaland', predicted: 8, actual: 5 },
      { playerId: 3, name: 'Saka', predicted: 4, actual: 4 },
    ],
    totalPredicted: 18,
    totalActual: 19,
  }

  it('shows total predicted points', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    expect(screen.getByText('18.0')).toBeInTheDocument()
    expect(screen.getByText(/Expected/i)).toBeInTheDocument()
  })

  it('shows total actual points', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    expect(screen.getByText('19')).toBeInTheDocument()
    expect(screen.getByText(/Actual/i)).toBeInTheDocument()
  })

  it('shows positive variance when outperforming', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    // 19 actual - 18 predicted = +1.0
    expect(screen.getByText('+1.0')).toBeInTheDocument()
  })

  it('shows negative variance when underperforming', () => {
    render(<VarianceAnalysis players={defaultProps.players} totalPredicted={25} totalActual={20} />)

    // 20 actual - 25 predicted = -5.0
    expect(screen.getByText('-5.0')).toBeInTheDocument()
  })

  it('shows luck meter label for positive variance', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    // When variance is positive, label shows "Positive"
    expect(screen.getByText(/Positive/i)).toBeInTheDocument()
  })

  it('shows luck meter label for negative variance', () => {
    render(<VarianceAnalysis players={defaultProps.players} totalPredicted={25} totalActual={20} />)

    expect(screen.getByText(/Negative/i)).toBeInTheDocument()
  })

  it('shows players sorted by variance (biggest overperformers first)', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    // Players should be listed with their variance
    const salahElement = screen.getByText('Salah')
    const haalandElement = screen.getByText('Haaland')

    // Salah should appear before Haaland (Salah +4 variance, Haaland -3 variance)
    expect(
      salahElement.compareDocumentPosition(haalandElement) & Node.DOCUMENT_POSITION_FOLLOWING
    ).toBeTruthy()
  })

  it('shows predicted points for each player', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    // Salah: predicted 6.0
    expect(screen.getAllByText('6.0').length).toBeGreaterThan(0)
  })

  it('shows actual points for each player', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    // Salah: actual 10
    expect(screen.getAllByText('10').length).toBeGreaterThan(0)
  })

  it('shows player variance with sign', () => {
    render(<VarianceAnalysis {...defaultProps} />)

    // Salah: 10 - 6 = +4.0
    expect(screen.getByText('+4.0')).toBeInTheDocument()
    // Haaland: 5 - 8 = -3.0
    expect(screen.getByText('-3.0')).toBeInTheDocument()
  })

  it('handles empty players array', () => {
    render(<VarianceAnalysis players={[]} totalPredicted={0} totalActual={0} />)

    // Should show "As Expected" when variance is 0
    expect(screen.getByText(/As Expected/i)).toBeInTheDocument()
  })

  it('handles zero variance', () => {
    render(
      <VarianceAnalysis
        players={[{ playerId: 1, name: 'Saka', predicted: 5, actual: 5 }]}
        totalPredicted={5}
        totalActual={5}
      />
    )

    // Variance is 0, should show neutral state
    expect(screen.getByText(/As Expected/i)).toBeInTheDocument()
  })
})
