import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { StatPanel, StatPanelGrid } from './StatPanel'

describe('StatPanel', () => {
  it('renders required props', () => {
    render(<StatPanel label="Points" value={72} />)

    expect(screen.getByText('Points')).toBeInTheDocument()
    expect(screen.getByText('72')).toBeInTheDocument()
  })

  it('applies optional props and className', () => {
    const { container } = render(
      <StatPanel
        label="Rank"
        value="12K"
        subValue="Up 1.2K"
        trend="up"
        highlight
        className="custom-panel"
      />
    )

    expect(screen.getByText('Up 1.2K')).toBeInTheDocument()
    expect(screen.getByText('+')).toBeInTheDocument()
    expect(container.querySelector('.custom-panel')).toBeInTheDocument()
  })

  it('renders grid wrapper children', () => {
    render(
      <StatPanelGrid className="grid-extra">
        <StatPanel label="A" value={1} />
        <StatPanel label="B" value={2} />
      </StatPanelGrid>
    )

    expect(screen.getByText('A')).toBeInTheDocument()
    expect(screen.getByText('B')).toBeInTheDocument()
  })
})
