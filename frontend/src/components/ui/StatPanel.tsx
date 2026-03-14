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
}: StatPanelProps) {
  const trendDisplay = {
    up: { text: '+', className: 'text-tt-green' },
    down: { text: '-', className: 'text-tt-red' },
    neutral: { text: '\u2014', className: 'text-foreground-muted' },
  }

  return (
    <div className={`stat-panel ${className}`}>
      <div className="flex items-baseline gap-2">
        <span
          className={`text-xl md:text-2xl font-bold ${highlight ? 'text-tt-yellow' : 'text-tt-white'}`}
        >
          {value}
        </span>
        {trend && (
          <span className={`text-xs font-medium ${trendDisplay[trend].className}`}>
            {trendDisplay[trend].text}
          </span>
        )}
      </div>
      <div className="text-[10px] md:text-xs uppercase tracking-wide text-tt-cyan mt-1">
        {label}
      </div>
      {subValue && (
        <div className="text-[11px] md:text-xs text-foreground-dim mt-0.5">{subValue}</div>
      )}
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
