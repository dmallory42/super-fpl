import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { BroadcastCard, BroadcastCardSection } from './BroadcastCard'

describe('BroadcastCard', () => {
  it('renders title and children', () => {
    render(<BroadcastCard title="Overview">Content block</BroadcastCard>)

    expect(screen.getByText('Overview')).toBeInTheDocument()
    expect(screen.getByText('Content block')).toBeInTheDocument()
  })

  it('renders optional props and class overrides', () => {
    const { container } = render(
      <BroadcastCard
        title="Header"
        headerAction={<span>Action</span>}
        className="extra-card"
        accentColor="magenta"
      >
        Child
      </BroadcastCard>
    )

    expect(screen.getByText('Action')).toBeInTheDocument()
    expect(container.querySelector('.extra-card')).toBeInTheDocument()
  })

  it('renders divided section helper', () => {
    const { container } = render(<BroadcastCardSection divided>Body</BroadcastCardSection>)
    expect(screen.getByText('Body')).toBeInTheDocument()
    expect(container.querySelector('.border-t')).toBeInTheDocument()
  })
})
