import { describe, it, expect } from 'vitest'
import { render } from '../../test/utils'
import {
  Skeleton,
  SkeletonText,
  SkeletonStatPanel,
  SkeletonStatGrid,
  SkeletonCard,
  SkeletonPitch,
  SkeletonTable,
} from './SkeletonLoader'

describe('SkeletonLoader', () => {
  it('renders basic skeleton components', () => {
    const { container } = render(
      <div>
        <Skeleton className="custom-skeleton" />
        <SkeletonText lines={3} />
        <SkeletonStatPanel />
        <SkeletonStatGrid />
      </div>
    )

    expect(container.querySelector('.custom-skeleton')).toBeInTheDocument()
    expect(container.querySelectorAll('.animate-shimmer').length).toBeGreaterThan(0)
  })

  it('renders card, pitch and table variants', () => {
    const { container } = render(
      <div>
        <SkeletonCard lines={2} />
        <SkeletonPitch />
        <SkeletonTable rows={3} cols={2} />
      </div>
    )

    expect(container.querySelectorAll('.broadcast-card').length).toBeGreaterThan(0)
    expect(container.querySelector('.pitch-texture')).toBeInTheDocument()
  })
})
