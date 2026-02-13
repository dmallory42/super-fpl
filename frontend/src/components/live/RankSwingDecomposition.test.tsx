import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, expect, vi } from 'vitest'
import { RankSwingDecomposition } from './RankSwingDecomposition'

describe('RankSwingDecomposition', () => {
  it('renders additive components and net swing', () => {
    render(
      <RankSwingDecomposition
        components={{
          captain: 2.5,
          differentials: 1.5,
          fixture: -1,
          fades: -0.5,
          net: 2.5,
        }}
        selectedTier="top_10k"
        onTierChange={() => {}}
      />
    )

    expect(screen.getByText('Net Rank Swing')).toBeInTheDocument()
    expect(screen.getAllByText('+2.5').length).toBeGreaterThan(0)
    expect(screen.getByText('-1.0')).toBeInTheDocument()
    expect(screen.getByText('Decomposition Sum')).toBeInTheDocument()
  })

  it('calls onTierChange when a tier is selected', async () => {
    const user = userEvent.setup()
    const onTierChange = vi.fn()

    render(
      <RankSwingDecomposition
        components={{
          captain: 0,
          differentials: 0,
          fixture: 0,
          fades: 0,
          net: 0,
        }}
        selectedTier="top_10k"
        onTierChange={onTierChange}
      />
    )

    await user.click(screen.getByRole('button', { name: '100K' }))
    expect(onTierChange).toHaveBeenCalledWith('top_100k')
  })
})
