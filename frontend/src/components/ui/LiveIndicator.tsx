interface LiveIndicatorProps {
  className?: string
  size?: 'sm' | 'md' | 'lg'
  showText?: boolean
}

export function LiveIndicator({
  className = '',
  size = 'md',
  showText = true,
}: LiveIndicatorProps) {
  const textSizes = {
    sm: 'text-sm',
    md: 'text-sm',
    lg: 'text-base',
  }

  return (
    <span className={`inline-flex items-center gap-1.5 ${className}`}>
      <span className="text-tt-red animate-blink">●</span>
      {showText && (
        <span className={`font-bold uppercase text-tt-red ${textSizes[size]}`}>
          LIVE
        </span>
      )}
    </span>
  )
}
