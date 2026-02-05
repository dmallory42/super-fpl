import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { SeasonReview } from './SeasonReview'
import type { ManagerHistoryResponse } from '../../api/client'

const createHistoryData = (
  overrides: Partial<ManagerHistoryResponse> = {}
): ManagerHistoryResponse => ({
  current: [
    {
      event: 1,
      points: 65,
      total_points: 65,
      rank: 1000000,
      rank_sort: 1000000,
      overall_rank: 1000000,
      bank: 0,
      value: 1000,
      event_transfers: 0,
      event_transfers_cost: 0,
      points_on_bench: 8,
    },
    {
      event: 2,
      points: 72,
      total_points: 137,
      rank: 800000,
      rank_sort: 800000,
      overall_rank: 800000,
      bank: 5,
      value: 1005,
      event_transfers: 1,
      event_transfers_cost: 0,
      points_on_bench: 5,
    },
    {
      event: 3,
      points: 45,
      total_points: 182,
      rank: 1200000,
      rank_sort: 1200000,
      overall_rank: 900000,
      bank: 10,
      value: 1010,
      event_transfers: 0,
      event_transfers_cost: 0,
      points_on_bench: 12,
    },
    {
      event: 4,
      points: 88,
      total_points: 270,
      rank: 500000,
      rank_sort: 500000,
      overall_rank: 600000,
      bank: 15,
      value: 1015,
      event_transfers: 2,
      event_transfers_cost: 4,
      points_on_bench: 3,
    },
  ],
  chips: [
    { name: 'wildcard', time: '2024-09-01T10:00:00Z', event: 2 },
    { name: 'bboost', time: '2024-10-15T10:00:00Z', event: 8 },
  ],
  ...overrides,
})

describe('SeasonReview', () => {
  describe('season summary stats', () => {
    it('displays total points', () => {
      const history = createHistoryData()
      const { container } = render(<SeasonReview history={history} />)

      // Total points from last gameweek
      expect(screen.getByText(/total points/i)).toBeInTheDocument()
      // Find the stat card with total points
      const totalPointsCard = container.querySelector('.text-emerald-400')
      expect(totalPointsCard?.textContent).toBe('270')
    })

    it('displays best gameweek', () => {
      const history = createHistoryData()
      const { container } = render(<SeasonReview history={history} />)

      // Best GW was 88 points in GW4
      expect(screen.getByText(/best gw/i)).toBeInTheDocument()
      // Find the green stat card (best GW)
      const bestGWCard = container.querySelector('.text-green-400')
      expect(bestGWCard?.textContent).toBe('88')
    })

    it('displays worst gameweek', () => {
      const history = createHistoryData()
      const { container } = render(<SeasonReview history={history} />)

      // Worst GW was 45 points in GW3
      expect(screen.getByText(/worst gw/i)).toBeInTheDocument()
      // Find the red stat card (worst GW)
      const worstGWCard = container.querySelector('.text-red-400.text-2xl')
      expect(worstGWCard?.textContent).toBe('45')
    })

    it('displays average points per gameweek', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      // Average: (65+72+45+88)/4 = 67.5
      expect(screen.getByText(/avg/i)).toBeInTheDocument()
      expect(screen.getByText('67.5')).toBeInTheDocument()
    })
  })

  describe('rank progression chart', () => {
    it('renders the rank chart', () => {
      const history = createHistoryData()
      const { container } = render(<SeasonReview history={history} />)

      // Check for SVG element for the chart
      expect(container.querySelector('svg')).toBeInTheDocument()
    })

    it('shows rank progression title', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      expect(screen.getByText(/rank progression/i)).toBeInTheDocument()
    })
  })

  describe('gameweek breakdown table', () => {
    it('displays gameweek numbers', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      // Table shows gameweeks in cells
      expect(screen.getByRole('cell', { name: 'GW1' })).toBeInTheDocument()
      expect(screen.getByRole('cell', { name: 'GW2' })).toBeInTheDocument()
      expect(screen.getByRole('cell', { name: 'GW3' })).toBeInTheDocument()
      expect(screen.getByRole('cell', { name: 'GW4' })).toBeInTheDocument()
    })

    it('displays points for each gameweek', () => {
      const history = createHistoryData()
      const { container } = render(<SeasonReview history={history} />)

      // GW points should be visible in the table
      const pointsCells = container.querySelectorAll('td.text-emerald-400')
      expect(pointsCells.length).toBeGreaterThanOrEqual(4)
    })

    it('displays transfers for each gameweek', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      // Should show transfers column header
      expect(screen.getByRole('columnheader', { name: /transfers/i })).toBeInTheDocument()
    })

    it('displays bench points for each gameweek', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      // Should show bench column header
      expect(screen.getByRole('columnheader', { name: /bench/i })).toBeInTheDocument()
    })
  })

  describe('chips timeline', () => {
    it('displays chips section header', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      expect(screen.getByRole('heading', { name: /chips used/i })).toBeInTheDocument()
    })

    it('displays used chips with gameweek', () => {
      const history = createHistoryData()
      render(<SeasonReview history={history} />)

      // Wildcard used in GW2
      expect(screen.getByText(/wildcard/i)).toBeInTheDocument()
      expect(screen.getByText('GW 2')).toBeInTheDocument()
    })

    it('handles empty chips array', () => {
      const history = createHistoryData({ chips: [] })
      render(<SeasonReview history={history} />)

      // Should still render without errors
      expect(screen.getByRole('heading', { name: /chips used/i })).toBeInTheDocument()
      expect(screen.getByText(/no chips used yet/i)).toBeInTheDocument()
    })

    it('handles undefined chips', () => {
      const history = createHistoryData({ chips: undefined })
      render(<SeasonReview history={history} />)

      // Should still render without errors
      expect(screen.getByRole('heading', { name: /chips used/i })).toBeInTheDocument()
      expect(screen.getByText(/no chips used yet/i)).toBeInTheDocument()
    })
  })

  describe('loading state', () => {
    it('shows message when no data', () => {
      render(<SeasonReview history={null} />)

      expect(screen.getByText(/no season data/i)).toBeInTheDocument()
    })
  })
})
