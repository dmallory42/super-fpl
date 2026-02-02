import { ReactNode } from 'react'

interface BroadcastCardProps {
  title?: string
  children: ReactNode
  className?: string
  headerAction?: ReactNode
  accentColor?: 'green' | 'purple' | 'highlight'
  animate?: boolean
  animationDelay?: number
}

export function BroadcastCard({
  title,
  children,
  className = '',
  headerAction,
  accentColor = 'green',
  animate = true,
  animationDelay = 0,
}: BroadcastCardProps) {
  const accentGradients = {
    green: 'from-fpl-green/30 via-fpl-green/10 to-transparent',
    purple: 'from-fpl-purple/30 via-fpl-purple/10 to-transparent',
    highlight: 'from-highlight/30 via-highlight/10 to-transparent',
  }

  const accentBorders = {
    green: 'border-fpl-green/30',
    purple: 'border-fpl-purple/30',
    highlight: 'border-highlight/30',
  }

  return (
    <div
      className={`
        broadcast-card
        ${animate ? 'animate-fade-in-up opacity-0' : ''}
        ${className}
      `}
      style={animate ? { animationDelay: `${animationDelay}ms` } : undefined}
    >
      {title && (
        <div
          className={`
            px-4 py-3 flex items-center justify-between
            bg-gradient-to-r ${accentGradients[accentColor]}
            border-b ${accentBorders[accentColor]}
          `}
        >
          <h3 className="font-display text-sm uppercase tracking-wider text-foreground">
            {title}
          </h3>
          {headerAction && <div>{headerAction}</div>}
        </div>
      )}
      <div className="p-4">{children}</div>
    </div>
  )
}

interface BroadcastCardSectionProps {
  children: ReactNode
  className?: string
  divided?: boolean
}

export function BroadcastCardSection({
  children,
  className = '',
  divided = false,
}: BroadcastCardSectionProps) {
  return (
    <div
      className={`
        ${divided ? 'border-t border-border pt-4 mt-4' : ''}
        ${className}
      `}
    >
      {children}
    </div>
  )
}
