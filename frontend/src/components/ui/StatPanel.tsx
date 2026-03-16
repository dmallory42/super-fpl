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
      <div className="bg-tt-cyan text-tt-black px-2 py-0.5 text-sm uppercase font-bold inline-block">
        {label}
      </div>
      <div className="flex items-baseline gap-2 mt-2">
        <span
          className={`text-3xl md:text-4xl font-bold ${highlight ? 'text-tt-yellow' : 'text-tt-white'}`}
        >
          {value}
        </span>
        {trend && (
          <span className={`text-sm font-medium ${trendDisplay[trend].className}`}>
            {trendDisplay[trend].text}
          </span>
        )}
      </div>
      {subValue && <div className="text-sm text-foreground-dim mt-1">{subValue}</div>}
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
