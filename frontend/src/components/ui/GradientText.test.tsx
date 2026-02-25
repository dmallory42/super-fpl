import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { GradientText } from './GradientText'

describe('GradientText', () => {
  it('renders with default variant', () => {
    render(<GradientText>Headline</GradientText>)

    expect(screen.getByText('Headline')).toBeInTheDocument()
  })

  it('supports optional variant, element and className', () => {
    const { container } = render(
      <GradientText
        variant="custom"
        customGradient="from-red-500 to-blue-500"
        as="h2"
        className="hero-text"
      >
        Custom
      </GradientText>
    )

    expect(screen.getByText('Custom')).toBeInTheDocument()
    expect(container.querySelector('h2.hero-text')).toBeInTheDocument()
  })
})
