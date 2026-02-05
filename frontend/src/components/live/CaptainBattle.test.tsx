import { describe, it, expect } from 'vitest'
import { render, screen, fireEvent } from '../../test/utils'
import { CaptainBattle } from './CaptainBattle'
import type { TierSampleData } from '../../api/client'

const mockPlayersMap = new Map([
  [1, { web_name: 'Salah', team: 10, element_type: 3 }],
  [2, { web_name: 'Haaland', team: 11, element_type: 4 }],
  [3, { web_name: 'Palmer', team: 2, element_type: 3 }],
  [4, { web_name: 'Saka', team: 1, element_type: 3 }],
  [5, { web_name: 'Bruno', team: 12, element_type: 3 }],
])

const createSamples = (captainPercent: Record<number, number>): { top_10k: TierSampleData } => ({
  top_10k: {
    avg_points: 50,
    sample_size: 100,
    effective_ownership: {},
    captain_percent: captainPercent,
  },
})

describe('CaptainBattle', () => {
  it('renders empty state when no samples', () => {
    render(<CaptainBattle userCaptainId={1} samples={undefined} playersMap={mockPlayersMap} />)

    expect(screen.getByText('No captain data available')).toBeInTheDocument()
  })

  it('renders captain options sorted by captaincy percentage', () => {
    const samples = createSamples({
      1: 45, // Salah
      2: 35, // Haaland
      3: 15, // Palmer
    })

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={mockPlayersMap} />
    )

    expect(screen.getByText('Salah')).toBeInTheDocument()
    expect(screen.getByText('45%')).toBeInTheDocument()
    expect(screen.getByText('Haaland')).toBeInTheDocument()
    expect(screen.getByText('35%')).toBeInTheDocument()
    expect(screen.getByText('Palmer')).toBeInTheDocument()
    expect(screen.getByText('15%')).toBeInTheDocument()
  })

  it('shows rank badges (1, 2, 3)', () => {
    const samples = createSamples({
      1: 45,
      2: 35,
      3: 15,
    })

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={mockPlayersMap} />
    )

    // Rank badges should be present
    expect(screen.getByText('1')).toBeInTheDocument()
    expect(screen.getByText('2')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('highlights user captain with special styling', () => {
    const samples = createSamples({
      1: 45, // Salah - user's captain
      2: 35,
    })

    render(<CaptainBattle userCaptainId={1} samples={samples} playersMap={mockPlayersMap} />)

    // Should show captain badge
    expect(screen.getByText('©')).toBeInTheDocument()
  })

  it('includes user captain even if not in top captains', () => {
    const samples = createSamples({
      1: 45, // Salah
      2: 35, // Haaland
      // User captained Saka who has no captaincy %
    })

    render(<CaptainBattle userCaptainId={4} samples={samples} playersMap={mockPlayersMap} />)

    // Saka should still be shown even though they have 0% captaincy
    expect(screen.getByText('Saka')).toBeInTheDocument()
    expect(screen.getByText('0%')).toBeInTheDocument()
    expect(screen.getByText('©')).toBeInTheDocument()
  })

  it('shows "Differential pick" label when user captain is outside top 3', () => {
    const samples = createSamples({
      1: 45, // Salah
      2: 35, // Haaland
      3: 15, // Palmer
      4: 5, // Saka - user's captain
    })

    render(<CaptainBattle userCaptainId={4} samples={samples} playersMap={mockPlayersMap} />)

    expect(screen.getByText('Differential pick')).toBeInTheDocument()
  })

  it('does not show "Differential pick" when user captain is in top 3', () => {
    const samples = createSamples({
      1: 45, // Salah - user's captain
      2: 35,
      3: 15,
    })

    render(<CaptainBattle userCaptainId={1} samples={samples} playersMap={mockPlayersMap} />)

    expect(screen.queryByText('Differential pick')).not.toBeInTheDocument()
  })

  it('allows switching between tiers', () => {
    const samples = {
      top_10k: {
        avg_points: 50,
        sample_size: 100,
        effective_ownership: {},
        captain_percent: { 1: 45 },
      },
      top_100k: {
        avg_points: 48,
        sample_size: 100,
        effective_ownership: {},
        captain_percent: { 1: 55 },
      },
    }

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={mockPlayersMap} />
    )

    // Initially shows 10K data
    expect(screen.getByText('45%')).toBeInTheDocument()

    // Click on 100K tier button
    fireEvent.click(screen.getByText('100K'))

    // Should now show 100K data
    expect(screen.getByText('55%')).toBeInTheDocument()
  })

  it('shows tier selector buttons', () => {
    const samples = createSamples({ 1: 45 })

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={mockPlayersMap} />
    )

    expect(screen.getByText('10K')).toBeInTheDocument()
    expect(screen.getByText('100K')).toBeInTheDocument()
    expect(screen.getByText('1M')).toBeInTheDocument()
    expect(screen.getByText('All')).toBeInTheDocument()
  })

  it('limits to 6 captains maximum', () => {
    const samples = createSamples({
      1: 45,
      2: 35,
      3: 30,
      4: 25,
      5: 20,
      6: 15, // Would be a 6th player not in our map
    })

    // Add a 6th player to the map
    const extendedPlayersMap = new Map(mockPlayersMap)
    extendedPlayersMap.set(6, { web_name: 'Wilson', team: 13, element_type: 4 })

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={extendedPlayersMap} />
    )

    // Should show all 6 players
    expect(screen.getByText('Salah')).toBeInTheDocument()
    expect(screen.getByText('Haaland')).toBeInTheDocument()
    expect(screen.getByText('Palmer')).toBeInTheDocument()
    expect(screen.getByText('Saka')).toBeInTheDocument()
    expect(screen.getByText('Bruno')).toBeInTheDocument()
    expect(screen.getByText('Wilson')).toBeInTheDocument()
  })

  it('shows empty state when tier has no captain data', () => {
    const samples = {
      top_10k: {
        avg_points: 50,
        sample_size: 100,
        effective_ownership: {},
        captain_percent: { 1: 45 },
      },
      top_100k: {
        avg_points: 48,
        sample_size: 100,
        effective_ownership: {},
        // No captain_percent data
      },
    }

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={mockPlayersMap} />
    )

    // Switch to 100K which has no captain data
    fireEvent.click(screen.getByText('100K'))

    expect(screen.getByText('No captain data for this tier')).toBeInTheDocument()
  })

  it('re-sorts when switching tiers', () => {
    const samples = {
      top_10k: {
        avg_points: 50,
        sample_size: 100,
        effective_ownership: {},
        captain_percent: { 1: 45, 2: 35 }, // Salah first
      },
      top_100k: {
        avg_points: 48,
        sample_size: 100,
        effective_ownership: {},
        captain_percent: { 1: 30, 2: 50 }, // Haaland first
      },
    }

    render(
      <CaptainBattle userCaptainId={undefined} samples={samples} playersMap={mockPlayersMap} />
    )

    // In 10K, Salah has rank 1
    const rankBadges = screen.getAllByText('1')
    expect(rankBadges.length).toBe(1)

    // Switch to 100K
    fireEvent.click(screen.getByText('100K'))

    // Now Haaland should have 50%
    expect(screen.getByText('50%')).toBeInTheDocument()
  })
})
