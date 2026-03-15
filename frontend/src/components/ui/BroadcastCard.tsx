import { ReactNode } from 'react'

type TeletextAccent = 'cyan' | 'yellow' | 'red' | 'blue' | 'magenta'

const accentBgColors: Record<TeletextAccent, string> = {
  cyan: 'bg-tt-cyan text-tt-black',
  yellow: 'bg-tt-yellow text-tt-black',
  red: 'bg-tt-red text-tt-white',
  blue: 'bg-tt-blue text-tt-white',
  magenta: 'bg-tt-magenta text-tt-black',
}

interface BroadcastCardProps {
  title?: string
  children: ReactNode
  className?: string
  headerAction?: ReactNode
  accentColor?: TeletextAccent
  animate?: boolean
  animationDelay?: number
}

export function BroadcastCard({
  title,
  children,
  className = '',
  headerAction,
  accentColor = 'cyan',
}: BroadcastCardProps) {
  return (
    <div className={`broadcast-card ${className}`}>
      {title && (
        <div
          className={`px-3 md:px-4 py-2.5 md:py-3 flex items-center justify-between ${accentBgColors[accentColor]}`}
        >
          <h3 className="text-sm md:text-base uppercase font-semibold">{title}</h3>
          {headerAction && <div>{headerAction}</div>}
        </div>
      )}
      <div className="p-3 md:p-4">{children}</div>
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
