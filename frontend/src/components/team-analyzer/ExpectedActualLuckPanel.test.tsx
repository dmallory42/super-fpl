import { describe, it, expect } from 'vitest'
import userEvent from '@testing-library/user-event'
import { render, screen, within } from '../../test/utils'
import {
  ExpectedActualLuckPanel,
  buildExpectedActualRows,
} from './ExpectedActualLuckPanel'
import type { ManagerSeasonAnalysisResponse } from '../../api/client'

const seasonAnalysis: ManagerSeasonAnalysisResponse = {
  manager_id: 123,
  generated_at: '2026-02-13T00:00:00Z',
  gameweeks: [
    {
      gameweek: 1,
      actual_points: 60,
      expected_points: 55,
      luck_delta: 5,
      overall_rank: 100000,
      event_transfers: 0,
      event_transfers_cost: 0,
      captain_impact: {
        captain_id: 1,
        multiplier: 2,
        actual_gain: 8,
        expected_gain: 6,
        luck_delta: 2,
      },
      chip_impact: { chips: [], active: null },
    },
    {
      gameweek: 2,
      actual_points: 52,
      expected_points: 56,
      luck_delta: -4,
      overall_rank: 120000,
      event_transfers: 1,
      event_transfers_cost: 4,
      captain_impact: {
        captain_id: 2,
        multiplier: 2,
        actual_gain: 4,
        expected_gain: 6,
        luck_delta: -2,
      },
      chip_impact: { chips: [], active: null },
    },
  ],
  transfer_analytics: [
    {
      gameweek: 2,
      transfer_count: 1,
      transfer_cost: 4,
      foresight_gain: 2,
      hindsight_gain: 1,
      net_gain: -3,
    },
  ],
  benchmarks: {
    overall: [
      { gameweek: 1, points: 50 },
      { gameweek: 2, points: 49 },
    ],
    top_10k: [
      { gameweek: 1, points: 62 },
      { gameweek: 2, points: 54 },
    ],
  },
  summary: {
    actual_points: 112,
    expected_points: 111,
    luck_delta: 1,
    captain_actual_gain: 12,
    captain_expected_gain: 12,
    captain_luck_delta: 0,
    transfer_foresight_gain: 2,
    transfer_hindsight_gain: 1,
    transfer_net_gain: -3,
  },
}

describe('ExpectedActualLuckPanel', () => {
  it('builds cumulative luck as running sum of per-GW deltas', () => {
    const rows = buildExpectedActualRows(seasonAnalysis, 'overall')

    expect(rows[0].cumulativeLuck).toBe(5)
    expect(rows[1].cumulativeLuck).toBe(1)
    expect(rows[1].cumulativeLuck).toBe(rows[0].luckDelta + rows[1].luckDelta)
  })

  it('switches benchmark and updates table values without schema mismatch', async () => {
    const user = userEvent.setup()
    const leagueMedianByGw = new Map<number, number>([
      [1, 58],
      [2, 50],
    ])

    render(<ExpectedActualLuckPanel seasonAnalysis={seasonAnalysis} leagueMedianByGw={leagueMedianByGw} />)

    await user.selectOptions(screen.getByLabelText('Benchmark'), 'league_median')

    const table = screen.getByTestId('expected-actual-table')
    const rows = within(table).getAllByRole('row').slice(1)

    expect(within(rows[0]).getByText('58.0')).toBeInTheDocument()
    expect(within(rows[1]).getByText('50.0')).toBeInTheDocument()
  })
})
