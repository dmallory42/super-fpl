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
    sm: 'text-[10px]',
    md: 'text-xs',
    lg: 'text-sm',
  }

  return (
    <span className={`inline-flex items-center gap-1.5 ${className}`}>
      <span className="text-tt-red animate-blink">●</span>
      {showText && (
        <span className={`font-bold uppercase tracking-wider text-tt-red ${textSizes[size]}`}>
          LIVE
        </span>
      )}
    </span>
  )
}
