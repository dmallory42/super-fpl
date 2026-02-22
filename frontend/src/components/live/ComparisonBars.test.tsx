import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '../../test/utils'
import { ComparisonBars } from './ComparisonBars'
import type { TierComparison } from '../../hooks/useLiveSamples'
import type { Tier } from '../../lib/tiers'

const noopTierChange = vi.fn()

function buildComparison(
  tier: Tier,
  tierLabel: string,
  avgPoints: number,
  difference: number
): TierComparison {
  return {
    tier,
    tierLabel,
    avgPoints,
    sampleSize: 1000,
    difference,
  }
}

describe('ComparisonBars marker layout', () => {
  it('combines tier labels and average points in summary rows', () => {
    const comparisons: TierComparison[] = [
      buildComparison('top_10k', 'Top 10K', 28, -4),
      buildComparison('top_100k', 'Top 100K', 28, -4),
      buildComparison('top_1m', 'Top 1M', 28, -4),
      buildComparison('overall', 'Overall', 22, 2),
    ]

    render(
      <ComparisonBars
        userPoints={24}
        comparisons={comparisons}
        selectedTier="top_10k"
        onTierChange={noopTierChange}
      />
    )

    expect(screen.getByTestId('tier-summary-top_10k')).toHaveTextContent('Top 10K')
    expect(screen.getByTestId('tier-summary-top_10k')).toHaveTextContent('28')
    expect(screen.getByTestId('tier-summary-top_100k')).toHaveTextContent('Top 100K')
    expect(screen.getByTestId('tier-summary-top_100k')).toHaveTextContent('28')
    expect(screen.getByTestId('tier-summary-top_1m')).toHaveTextContent('Top 1M')
    expect(screen.getByTestId('tier-summary-top_1m')).toHaveTextContent('28')
    expect(screen.getByTestId('tier-summary-overall')).toHaveTextContent('Overall')
    expect(screen.getByTestId('tier-summary-overall')).toHaveTextContent('22')

    expect(screen.queryByTestId('tier-chip-top_10k')).not.toBeInTheDocument()
  })

  it('spreads overlapping tier markers horizontally', () => {
    const comparisons: TierComparison[] = [
      buildComparison('top_10k', 'Top 10K', 30, -3),
      buildComparison('top_100k', 'Top 100K', 30, -3),
      buildComparison('top_1m', 'Top 1M', 30, -3),
      buildComparison('overall', 'Overall', 24, 3),
    ]

    render(
      <ComparisonBars
        userPoints={27}
        comparisons={comparisons}
        selectedTier="top_10k"
        onTierChange={noopTierChange}
      />
    )

    expect(screen.getByTestId('tier-marker-top_10k')).toHaveStyle({ marginLeft: '-22px' })
    expect(screen.getByTestId('tier-marker-top_100k')).toHaveStyle({ marginLeft: '0px' })
    expect(screen.getByTestId('tier-marker-top_1m')).toHaveStyle({ marginLeft: '22px' })
  })

  it('stores tier point values on marker hover title', () => {
    const comparisons: TierComparison[] = [
      buildComparison('top_10k', 'Top 10K', 28, -4),
      buildComparison('top_100k', 'Top 100K', 28, -4),
      buildComparison('top_1m', 'Top 1M', 27, -3),
      buildComparison('overall', 'Overall', 23, 1),
    ]

    render(
      <ComparisonBars
        userPoints={24}
        comparisons={comparisons}
        selectedTier="top_10k"
        onTierChange={noopTierChange}
      />
    )

    expect(screen.getByTestId('tier-marker-top_10k')).toHaveAttribute('title', 'Top 10K: 28 pts')
    expect(screen.getByTestId('tier-marker-top_100k')).toHaveAttribute('title', 'Top 100K: 28 pts')
    expect(screen.getByTestId('tier-marker-top_1m')).toHaveAttribute('title', 'Top 1M: 27 pts')
    expect(screen.getByTestId('tier-tooltip-top_10k')).toHaveTextContent('Top 10K 28')
    expect(screen.getByTestId('tier-tooltip-top_100k')).toHaveTextContent('Top 100K 28')
  })
})
