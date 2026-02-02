import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { RiskMeter } from './RiskMeter'
import type { RiskScore } from '../../api/client'

const createRiskScore = (score: number, level: 'low' | 'medium' | 'high', captainRisk: number): RiskScore => ({
  score,
  level,
  breakdown: {
    captain_risk: captainRisk,
    playing_count: 11,
  },
})

describe('RiskMeter', () => {
  describe('full view (non-compact)', () => {
    it('renders manager name', () => {
      const riskScore = createRiskScore(45, 'medium', 30)
      render(<RiskMeter managerName="Test Manager" riskScore={riskScore} />)

      expect(screen.getByText('Test Manager')).toBeInTheDocument()
    })

    it('renders risk level', () => {
      const riskScore = createRiskScore(45, 'medium', 30)
      render(<RiskMeter managerName="Test Manager" riskScore={riskScore} />)

      expect(screen.getByText('medium risk')).toBeInTheDocument()
    })

    it('renders score value', () => {
      const riskScore = createRiskScore(45.5, 'medium', 30)
      render(<RiskMeter managerName="Test Manager" riskScore={riskScore} />)

      expect(screen.getByText('Score: 45.5')).toBeInTheDocument()
    })

    it('renders captain risk value', () => {
      const riskScore = createRiskScore(45, 'medium', 30.7)
      render(<RiskMeter managerName="Test Manager" riskScore={riskScore} />)

      expect(screen.getByText('Captain risk: 31')).toBeInTheDocument()
    })

    it('applies correct color class for low risk', () => {
      const riskScore = createRiskScore(20, 'low', 10)
      render(<RiskMeter managerName="Test" riskScore={riskScore} />)

      expect(screen.getByText('low risk')).toHaveClass('text-green-400')
    })

    it('applies correct color class for medium risk', () => {
      const riskScore = createRiskScore(50, 'medium', 30)
      render(<RiskMeter managerName="Test" riskScore={riskScore} />)

      expect(screen.getByText('medium risk')).toHaveClass('text-yellow-400')
    })

    it('applies correct color class for high risk', () => {
      const riskScore = createRiskScore(80, 'high', 50)
      render(<RiskMeter managerName="Test" riskScore={riskScore} />)

      expect(screen.getByText('high risk')).toHaveClass('text-red-400')
    })
  })

  describe('compact view', () => {
    it('renders compact layout when compact prop is true', () => {
      const riskScore = createRiskScore(45, 'medium', 30)
      render(<RiskMeter riskScore={riskScore} compact />)

      // In compact mode, only the level text is shown, not "risk" suffix
      expect(screen.getByText('medium')).toBeInTheDocument()
      // Should NOT show the full "medium risk" text
      expect(screen.queryByText('medium risk')).not.toBeInTheDocument()
    })

    it('does not render manager name in compact mode', () => {
      const riskScore = createRiskScore(45, 'medium', 30)
      render(<RiskMeter managerName="Test Manager" riskScore={riskScore} compact />)

      expect(screen.queryByText('Test Manager')).not.toBeInTheDocument()
    })

    it('does not render score details in compact mode', () => {
      const riskScore = createRiskScore(45.5, 'medium', 30)
      render(<RiskMeter riskScore={riskScore} compact />)

      expect(screen.queryByText(/Score:/)).not.toBeInTheDocument()
      expect(screen.queryByText(/Captain risk:/)).not.toBeInTheDocument()
    })
  })

  describe('edge cases', () => {
    it('caps percentage at 100 for scores over 100', () => {
      const riskScore = createRiskScore(150, 'high', 80)
      const { container } = render(<RiskMeter riskScore={riskScore} />)

      // The progress bar width should be capped at 100%
      const progressBar = container.querySelector('[style*="width"]')
      expect(progressBar).toHaveStyle({ width: '100%' })
    })

    it('handles score of 0', () => {
      const riskScore = createRiskScore(0, 'low', 0)
      render(<RiskMeter managerName="Test" riskScore={riskScore} />)

      expect(screen.getByText('Score: 0.0')).toBeInTheDocument()
    })
  })
})
