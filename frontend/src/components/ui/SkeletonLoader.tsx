interface SkeletonProps {
  className?: string
}

export function Skeleton({ className = '' }: SkeletonProps) {
  return <div className={`animate-blink text-tt-dim ${className}`}>{'█'.repeat(8)}</div>
}

export function SkeletonText({
  lines = 1,
  className = '',
}: {
  lines?: number
  className?: string
}) {
  return (
    <div className={`space-y-2 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className="animate-blink text-tt-dim">
          {'█'.repeat(i === lines - 1 && lines > 1 ? 16 : 24)}
        </div>
      ))}
    </div>
  )
}

export function SkeletonStatPanel() {
  return (
    <div className="stat-panel">
      <div className="space-y-2">
        <div className="animate-blink text-tt-dim">{'█'.repeat(6)}</div>
        <div className="animate-blink text-tt-dim text-xs">{'█'.repeat(10)}</div>
      </div>
    </div>
  )
}

export function SkeletonStatGrid() {
  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      {Array.from({ length: 4 }).map((_, i) => (
        <SkeletonStatPanel key={i} />
      ))}
    </div>
  )
}

export function SkeletonCard({ lines = 3 }: { lines?: number }) {
  return (
    <div className="broadcast-card">
      <div className="px-4 py-3 border-b border-border">
        <div className="animate-blink text-tt-dim">{'█'.repeat(12)}</div>
      </div>
      <div className="p-4">
        <SkeletonText lines={lines} />
      </div>
    </div>
  )
}

export function SkeletonPitch() {
  return (
    <div className="pitch-texture p-4">
      <div className="space-y-6 py-4">
        {[3, 4, 4, 1].map((count, rowIdx) => (
          <div key={rowIdx} className="flex justify-center gap-4">
            {Array.from({ length: count }).map((_, i) => (
              <SkeletonPlayer key={i} />
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}

function SkeletonPlayer() {
  return (
    <div className="flex flex-col items-center gap-1">
      <div className="animate-blink text-tt-dim text-2xl">{'█'.repeat(3)}</div>
      <div className="animate-blink text-tt-dim text-xs">{'█'.repeat(6)}</div>
    </div>
  )
}

export function SkeletonTable({ rows = 5, cols = 4 }: { rows?: number; cols?: number }) {
  return (
    <div className="broadcast-card overflow-hidden">
      <div className="px-4 py-3">
        <div className="animate-blink text-tt-dim">{'█'.repeat(12)}</div>
      </div>
      <div className="divide-y divide-border">
        {Array.from({ length: rows }).map((_, rowIdx) => (
          <div key={rowIdx} className="flex items-center gap-4 px-4 py-3">
            {Array.from({ length: cols }).map((_, colIdx) => (
              <div key={colIdx} className="animate-blink text-tt-dim">
                {'█'.repeat(colIdx === 0 ? 8 : 4)}
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
