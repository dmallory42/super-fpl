import { describe, it, expect } from 'vitest'
import { render, screen, within } from '../../test/utils'
import { TransferQualityScorecard } from './TransferQualityScorecard'
import type { ManagerSeasonAnalysisResponse } from '../../api/client'

const baseAnalysis: ManagerSeasonAnalysisResponse = {
  manager_id: 123,
  generated_at: '2026-02-13T00:00:00Z',
  gameweeks: [],
  transfer_analytics: [],
  summary: {
    actual_points: 0,
    expected_points: 0,
    luck_delta: 0,
    captain_actual_gain: 0,
    captain_expected_gain: 0,
    captain_luck_delta: 0,
    transfer_foresight_gain: 0,
    transfer_hindsight_gain: 0,
    transfer_net_gain: 0,
  },
}

describe('TransferQualityScorecard', () => {
  it('renders empty state when no transfer rows exist', () => {
    render(<TransferQualityScorecard seasonAnalysis={baseAnalysis} />)

    expect(screen.getByText('No transfer weeks recorded yet.')).toBeInTheDocument()
  })

  it('renders row metrics and aggregate totals', () => {
    const analysis: ManagerSeasonAnalysisResponse = {
      ...baseAnalysis,
      transfer_analytics: [
        {
          gameweek: 3,
          transfer_count: 2,
          transfer_cost: 4,
          foresight_gain: 1,
          hindsight_gain: -2,
          net_gain: -6,
        },
        {
          gameweek: 7,
          transfer_count: 1,
          transfer_cost: 0,
          foresight_gain: 2,
          hindsight_gain: 3,
          net_gain: 3,
        },
      ],
    }

    render(<TransferQualityScorecard seasonAnalysis={analysis} />)

    const table = screen.getByTestId('transfer-quality-table')
    const rows = within(table).getAllByRole('row').slice(1)

    expect(within(rows[0]).getByText('3')).toBeInTheDocument()
    expect(within(rows[1]).getByText('7')).toBeInTheDocument()

    const transferWeeksCard = screen.getByText('Transfer Weeks').parentElement
    expect(transferWeeksCard).not.toBeNull()
    expect(transferWeeksCard).toHaveTextContent('Transfer Weeks2')
    const foresightCard = screen.getByText('Foresight Gain').parentElement
    expect(foresightCard).not.toBeNull()
    expect(foresightCard).toHaveTextContent('Foresight Gain+3.0')
    const hindsightCard = screen.getByText('Hindsight Gain').parentElement
    expect(hindsightCard).not.toBeNull()
    expect(hindsightCard).toHaveTextContent('Hindsight Gain+1.0')
  })
})
