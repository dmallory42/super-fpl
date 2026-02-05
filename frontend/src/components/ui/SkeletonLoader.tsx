interface SkeletonProps {
  className?: string
}

export function Skeleton({ className = '' }: SkeletonProps) {
  return (
    <div
      className={`
        animate-shimmer rounded
        bg-gradient-to-r from-surface via-surface-elevated to-surface
        bg-[length:200%_100%]
        ${className}
      `}
    />
  )
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
        <Skeleton key={i} className={`h-4 ${i === lines - 1 && lines > 1 ? 'w-3/4' : 'w-full'}`} />
      ))}
    </div>
  )
}

export function SkeletonStatPanel() {
  return (
    <div className="stat-panel">
      <div className="pl-3 space-y-2">
        <Skeleton className="h-8 w-20" />
        <Skeleton className="h-3 w-16" />
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
        <Skeleton className="h-4 w-32" />
      </div>
      <div className="p-4">
        <SkeletonText lines={lines} />
      </div>
    </div>
  )
}

export function SkeletonPitch() {
  return (
    <div className="pitch-texture rounded-lg p-4">
      <div className="space-y-6 py-4">
        {/* Forward row */}
        <div className="flex justify-center gap-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <SkeletonPlayer key={i} />
          ))}
        </div>
        {/* Midfield row */}
        <div className="flex justify-center gap-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonPlayer key={i} />
          ))}
        </div>
        {/* Defense row */}
        <div className="flex justify-center gap-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonPlayer key={i} />
          ))}
        </div>
        {/* GK */}
        <div className="flex justify-center">
          <SkeletonPlayer />
        </div>
      </div>
    </div>
  )
}

function SkeletonPlayer() {
  return (
    <div className="flex flex-col items-center gap-1">
      <Skeleton className="w-12 h-12 rounded-full" />
      <Skeleton className="h-4 w-14 rounded" />
      <Skeleton className="h-3 w-8 rounded" />
    </div>
  )
}

export function SkeletonTable({ rows = 5, cols = 4 }: { rows?: number; cols?: number }) {
  return (
    <div className="broadcast-card overflow-hidden">
      <div className="px-4 py-3 bg-surface-elevated">
        <Skeleton className="h-4 w-32" />
      </div>
      <div className="divide-y divide-border">
        {Array.from({ length: rows }).map((_, rowIdx) => (
          <div key={rowIdx} className="flex items-center gap-4 px-4 py-3">
            {Array.from({ length: cols }).map((_, colIdx) => (
              <Skeleton key={colIdx} className={`h-4 ${colIdx === 0 ? 'w-24' : 'w-16'}`} />
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
