import { describe, it, expect } from 'vitest'
import userEvent from '@testing-library/user-event'
import { render, screen, within } from '../../test/utils'
import { DecisionDeltaModule } from './DecisionDeltaModule'
import type { LeagueSeasonManager } from '../../api/client'

function createManager(
  managerId: number,
  rank: number,
  managerName: string,
  teamName: string,
  decision: LeagueSeasonManager['decision_quality']
): LeagueSeasonManager {
  return {
    manager_id: managerId,
    manager_name: managerName,
    team_name: teamName,
    rank,
    total: 1000,
    gameweeks: [
      {
        gameweek: 1,
        actual_points: 50,
        expected_points: 45,
        luck_delta: 5,
        event_transfers: 1,
        event_transfers_cost: 0,
        captain_actual_gain: decision.captain_gains,
        missing: false,
      },
    ],
    decision_quality: decision,
  }
}

describe('DecisionDeltaModule', () => {
  it('sorts deterministically with rank tiebreaker', () => {
    const managers: LeagueSeasonManager[] = [
      createManager(200, 2, 'B Manager', 'Beta XI', {
        captain_gains: 10,
        hit_cost: 8,
        transfer_net_gain: 12,
        hit_roi: 1.5,
        chip_events: 1,
      }),
      createManager(100, 1, 'A Manager', 'Alpha XI', {
        captain_gains: 9,
        hit_cost: 4,
        transfer_net_gain: 12,
        hit_roi: 1.2,
        chip_events: 2,
      }),
      createManager(300, 3, 'C Manager', 'Gamma XI', {
        captain_gains: 6,
        hit_cost: 12,
        transfer_net_gain: 5,
        hit_roi: 0.4,
        chip_events: 0,
      }),
    ]

    render(<DecisionDeltaModule managers={managers} />)

    const table = screen.getByTestId('decision-delta-table')
    const rows = within(table).getAllByRole('row').slice(1)

    expect(within(rows[0]).getByText('A Manager')).toBeInTheDocument()
    expect(within(rows[1]).getByText('B Manager')).toBeInTheDocument()
    expect(within(rows[2]).getByText('C Manager')).toBeInTheDocument()
  })

  it('shows metric definitions and computes median deltas', async () => {
    const user = userEvent.setup()
    const managers: LeagueSeasonManager[] = [
      createManager(1, 1, 'Alpha', 'Alpha XI', {
        captain_gains: 12,
        hit_cost: 4,
        transfer_net_gain: 10,
        hit_roi: 2.5,
        chip_events: 1,
      }),
      createManager(2, 2, 'Beta', 'Beta XI', {
        captain_gains: 8,
        hit_cost: 8,
        transfer_net_gain: 6,
        hit_roi: 0.75,
        chip_events: 0,
      }),
      createManager(3, 3, 'Gamma', 'Gamma XI', {
        captain_gains: 4,
        hit_cost: 12,
        transfer_net_gain: 2,
        hit_roi: -0.5,
        chip_events: 0,
      }),
    ]

    render(<DecisionDeltaModule managers={managers} />)

    await user.selectOptions(screen.getByLabelText('Metric'), 'hit_cost')

    expect(
      screen.getByText('Total points spent on transfer hits from `decision_quality.hit_cost`.')
    ).toBeInTheDocument()
    expect(screen.getByText('League median (Hit Cost):')).toBeInTheDocument()
    expect(screen.getByText('League median (Hit Cost):').parentElement).toHaveTextContent(
      'League median (Hit Cost):8'
    )

    const table = screen.getByTestId('decision-delta-table')
    const rows = within(table).getAllByRole('row').slice(1)

    expect(within(rows[0]).getByText('Alpha')).toBeInTheDocument()
    expect(within(rows[0]).getByText('-4.0')).toBeInTheDocument()
  })
})
