import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { GoodWeekBanner } from './GoodWeekBanner'

describe('GoodWeekBanner', () => {
  it('renders highlighted message for strong margin', () => {
    render(<GoodWeekBanner margin={24.7} rankMovement={1200} />)

    expect(screen.getByText('Outstanding!')).toBeInTheDocument()
    expect(screen.getByText('+25')).toBeInTheDocument()
    expect(screen.getByText('1.2K')).toBeInTheDocument()
  })

  it('returns null when margin is below threshold', () => {
    const { container } = render(<GoodWeekBanner margin={9.9} rankMovement={300} />)
    expect(container.firstChild).toBeNull()
  })

  it('hides rank movement block when movement is non-positive', () => {
    render(<GoodWeekBanner margin={18} rankMovement={0} />)

    expect(screen.getByText('Great Week!')).toBeInTheDocument()
    expect(screen.queryByText('places gained')).not.toBeInTheDocument()
  })
})
