import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { RankProjection } from './RankProjection'

describe('RankProjection', () => {
  it('shows current rank', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        currentPoints={45}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // Should show the current rank formatted
    expect(screen.getByText('50K')).toBeInTheDocument()
  })

  it('shows gameweek progress percentage', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        currentPoints={45}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // 5/10 = 50% complete
    expect(screen.getByText('50%')).toBeInTheDocument()
    expect(screen.getByText('GW Complete')).toBeInTheDocument()
  })

  it('shows rank movement direction when improving', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        currentPoints={45}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // Improving from 60K to 50K = gaining ranks
    expect(screen.getByText(/↑/)).toBeInTheDocument()
    // The velocity message shows the rank improvement
    expect(screen.getByText(/Gaining/i)).toBeInTheDocument()
  })

  it('shows rank movement direction when dropping', () => {
    render(
      <RankProjection
        currentRank={70000}
        previousRank={60000}
        currentPoints={35}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // Dropping from 60K to 70K = losing ranks
    expect(screen.getByText(/↓/)).toBeInTheDocument()
  })

  it('shows pace comparison when ahead of tier', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        currentPoints={45}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // 45 pts vs 40 pts = +5 ahead
    expect(screen.getByText(/\+5/)).toBeInTheDocument()
  })

  it('shows pace comparison when behind tier', () => {
    render(
      <RankProjection
        currentRank={70000}
        previousRank={60000}
        currentPoints={35}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // 35 pts vs 40 pts = -5 behind
    expect(screen.getByText(/-5/)).toBeInTheDocument()
  })

  it('shows velocity estimate when gaining places', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        currentPoints={45}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    // Gained 10K in 50%, so projecting another 10K gain
    expect(screen.getByText(/Gaining/i)).toBeInTheDocument()
  })

  it('shows velocity estimate when losing places', () => {
    render(
      <RankProjection
        currentRank={70000}
        previousRank={60000}
        currentPoints={35}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText(/Losing/i)).toBeInTheDocument()
  })

  it('formats large ranks correctly', () => {
    render(
      <RankProjection
        currentRank={1500000}
        previousRank={1600000}
        currentPoints={30}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText('1.5M')).toBeInTheDocument()
  })

  it('shows "steady" when no rank change', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={50000}
        currentPoints={40}
        tierAvgPoints={40}
        fixturesFinished={5}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText(/Steady/i)).toBeInTheDocument()
  })

  it('handles 100% gameweek completion', () => {
    render(
      <RankProjection
        currentRank={50000}
        previousRank={60000}
        currentPoints={60}
        tierAvgPoints={55}
        fixturesFinished={10}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText('100%')).toBeInTheDocument()
  })

  it('handles 0% gameweek completion', () => {
    render(
      <RankProjection
        currentRank={60000}
        previousRank={60000}
        currentPoints={0}
        tierAvgPoints={0}
        fixturesFinished={0}
        fixturesTotal={10}
      />
    )

    expect(screen.getByText('0%')).toBeInTheDocument()
  })
})
