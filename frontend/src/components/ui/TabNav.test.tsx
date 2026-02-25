import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '../../test/utils'
import { TabNav } from './TabNav'

describe('TabNav', () => {
  it('renders tabs with active state', () => {
    render(
      <TabNav
        tabs={[
          { id: 'a', label: 'A' },
          { id: 'b', label: 'B' },
        ]}
        activeTab="b"
        onTabChange={() => {}}
      />
    )

    const active = screen.getByRole('button', { name: 'B' })
    expect(active.className).toContain('active')
  })

  it('fires onTabChange when clicked and supports className', () => {
    const onTabChange = vi.fn()
    const { container } = render(
      <TabNav
        tabs={[{ id: 'live', label: 'Live', isLive: true }]}
        activeTab="live"
        onTabChange={onTabChange}
        className="tabs-extra"
      />
    )

    fireEvent.click(screen.getByRole('button', { name: /Live/i }))
    expect(onTabChange).toHaveBeenCalledWith('live')
    expect(container.querySelector('.tabs-extra')).toBeInTheDocument()
  })
})
