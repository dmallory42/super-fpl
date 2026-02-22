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
          transfers: [
            {
              out_id: 101,
              out_name: 'OutOne',
              in_id: 201,
              in_name: 'InOne',
              foresight_gain: 0.4,
              hindsight_gain: -1.2,
            },
          ],
        },
        {
          gameweek: 7,
          transfer_count: 1,
          transfer_cost: 0,
          foresight_gain: 2,
          hindsight_gain: 3,
          net_gain: 3,
          transfers: [
            {
              out_id: 102,
              out_name: 'OutTwo',
              in_id: 202,
              in_name: 'InTwo',
              foresight_gain: 2.0,
              hindsight_gain: 3.0,
            },
          ],
        },
      ],
    }

    render(<TransferQualityScorecard seasonAnalysis={analysis} />)

    const table = screen.getByTestId('transfer-quality-table')
    const rows = within(table).getAllByRole('row').slice(1)

    expect(within(rows[0]).getByText('3')).toBeInTheDocument()
    expect(within(rows[1]).getByText('7')).toBeInTheDocument()
    expect(within(rows[0]).getByText(/OutOne/)).toBeInTheDocument()
    expect(within(rows[0]).getByText(/InOne/)).toBeInTheDocument()
    expect(within(rows[1]).getByText(/OutTwo/)).toBeInTheDocument()
    expect(within(rows[1]).getByText(/InTwo/)).toBeInTheDocument()

    expect(screen.getByTestId('transfer-quality-weeks')).toHaveTextContent('Transfer Weeks2')
    expect(screen.getByTestId('transfer-quality-expected')).toHaveTextContent(
      'Expected Gain (Snapshot)+3.0'
    )
    expect(screen.getByTestId('transfer-quality-expected')).toHaveTextContent('2/2 GWs covered')
    expect(screen.getByTestId('transfer-quality-realized')).toHaveTextContent('Realized Gain+1.0')
    expect(screen.queryByText('Net Gain')).not.toBeInTheDocument()

    const firstExpectedCell = within(rows[0]).getByText('+1.0')
    const firstRealizedCell = within(rows[0]).getByText('-2.0')
    const secondExpectedCell = within(rows[1]).getByText('+2.0')
    const secondRealizedCell = within(rows[1]).getByText('+3.0')

    expect(firstExpectedCell).toHaveClass('text-fpl-green')
    expect(firstRealizedCell).toHaveClass('text-destructive')
    expect(secondExpectedCell).toHaveClass('text-fpl-green')
    expect(secondRealizedCell).toHaveClass('text-fpl-green')
  })

  it('shows N/A expected gain when snapshots are incomplete for a gameweek', () => {
    const analysis: ManagerSeasonAnalysisResponse = {
      ...baseAnalysis,
      transfer_analytics: [
        {
          gameweek: 5,
          transfer_count: 1,
          transfer_cost: 0,
          foresight_gain: null,
          foresight_complete: false,
          hindsight_gain: 2,
          net_gain: 2,
          transfers: [
            {
              out_id: 111,
              out_name: 'OutMissing',
              in_id: 211,
              in_name: 'InMissing',
              foresight_gain: null,
              hindsight_gain: 2,
            },
          ],
        },
      ],
    }

    render(<TransferQualityScorecard seasonAnalysis={analysis} />)

    expect(screen.getByTestId('transfer-quality-expected')).toHaveTextContent('N/A')
    expect(screen.getByTestId('transfer-quality-expected')).toHaveTextContent('0/1 GWs covered')

    const table = screen.getByTestId('transfer-quality-table')
    const rows = within(table).getAllByRole('row').slice(1)
    expect(within(rows[0]).getByText('N/A')).toBeInTheDocument()
  })
})
