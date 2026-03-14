import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { GradientText, TeletextText } from './GradientText'

describe('TeletextText', () => {
  it('renders with default cyan color', () => {
    render(<TeletextText>Headline</TeletextText>)
    expect(screen.getByText('Headline')).toBeInTheDocument()
  })

  it('supports color, element and className', () => {
    const { container } = render(
      <TeletextText color="yellow" as="h2" className="hero-text">
        Custom
      </TeletextText>
    )

    expect(screen.getByText('Custom')).toBeInTheDocument()
    expect(container.querySelector('h2.hero-text')).toBeInTheDocument()
  })

  it('exports GradientText as backwards-compat alias', () => {
    render(<GradientText>Legacy</GradientText>)
    expect(screen.getByText('Legacy')).toBeInTheDocument()
  })
})
