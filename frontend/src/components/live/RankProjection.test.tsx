import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { RankProjection } from './RankProjection'

describe('RankProjection', () => {
  it('shows current rank', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText('50K')).toBeInTheDocument()
  })

  it('shows match count progress instead of percentage', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText('5/10')).toBeInTheDocument()
    expect(screen.getByText('Matches')).toBeInTheDocument()
    expect(screen.queryByText('GW Complete')).not.toBeInTheDocument()
  })

  it('shows movement direction when improving', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText(/↑/)).toBeInTheDocument()
  })

  it('shows movement direction when dropping', () => {
    render(
      <RankProjection
        currentRank={70000}
        previousRank={60000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText(/↓/)).toBeInTheDocument()
  })

  it('formats large ranks correctly', () => {
    render(
      <RankProjection
        currentRank={1500000}
        previousRank={1600000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText('1.5M')).toBeInTheDocument()
  })

  it('hides movement indicator when no rank change', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={50000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.queryByText(/↑|↓/)).not.toBeInTheDocument()
  })

  it('handles complete and empty gameweek counts', () => {
    const { rerender } = render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        fixturesFinished={10}
        fixturesTotal={10}
      />
    )
    expect(screen.getByText('10/10')).toBeInTheDocument()

    rerender(
      <RankProjection
        currentRank={60000}
        previousRank={60000}
        fixturesFinished={0}
        fixturesTotal={10}
      />
    )
    expect(screen.getByText('0/10')).toBeInTheDocument()
  })

  it('no longer renders the tier average strip', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.queryByText(/vs Tier Avg/i)).not.toBeInTheDocument()
  })
})
