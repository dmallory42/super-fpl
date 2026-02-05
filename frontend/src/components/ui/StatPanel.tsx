interface StatPanelProps {
  label: string
  value: string | number
  highlight?: boolean
  trend?: 'up' | 'down' | 'neutral'
  subValue?: string
  className?: string
  animationDelay?: number
}

export function StatPanel({
  label,
  value,
  highlight = false,
  trend,
  subValue,
  className = '',
  animationDelay = 0,
}: StatPanelProps) {
  const trendColors = {
    up: 'text-fpl-green',
    down: 'text-destructive',
    neutral: 'text-foreground-muted',
  }

  const trendIcons = {
    up: '\u25B2', // Triangle up
    down: '\u25BC', // Triangle down
    neutral: '\u2014', // Em dash
  }

  return (
    <div
      className={`
        stat-panel group
        ${highlight ? 'ring-1 ring-fpl-green/30' : ''}
        animate-fade-in-up opacity-0
        ${className}
      `}
      style={{ animationDelay: `${animationDelay}ms` }}
    >
      {/* Accent bar */}
      <div
        className={`absolute left-0 top-0 bottom-0 w-1 rounded-l transition-all duration-300 ${
          highlight
            ? 'bg-gradient-to-b from-fpl-green to-fpl-purple'
            : 'bg-border group-hover:bg-fpl-green/50'
        }`}
      />

      {/* Content */}
      <div className="pl-3">
        <div className="flex items-baseline gap-2">
          <span
            className={`font-mono text-2xl font-bold tracking-tight ${
              highlight ? 'gradient-text' : 'text-foreground'
            }`}
          >
            {value}
          </span>
          {trend && (
            <span className={`text-xs font-medium ${trendColors[trend]}`}>{trendIcons[trend]}</span>
          )}
        </div>
        <div className="text-xs font-display uppercase tracking-wider text-foreground-muted mt-1">
          {label}
        </div>
        {subValue && <div className="text-xs text-foreground-dim mt-0.5">{subValue}</div>}
      </div>
    </div>
  )
}

interface StatPanelGridProps {
  children: React.ReactNode
  className?: string
}

export function StatPanelGrid({ children, className = '' }: StatPanelGridProps) {
  return <div className={`grid grid-cols-2 md:grid-cols-4 gap-4 ${className}`}>{children}</div>
}
