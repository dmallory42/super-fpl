import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '../../test/utils'
import { EmptyState } from './EmptyState'

describe('EmptyState', () => {
  it('renders required props', () => {
    render(<EmptyState title="No Data" />)

    expect(screen.getByText('No Data')).toBeInTheDocument()
  })

  it('renders optional content and action callback', () => {
    const onClick = vi.fn()
    render(
      <EmptyState
        title="Nothing here"
        description="Try adjusting filters"
        action={{ label: 'Retry', onClick }}
        className="empty-extra"
      />
    )

    expect(screen.getByText('Try adjusting filters')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Retry'))
    expect(onClick).toHaveBeenCalled()
  })
})
