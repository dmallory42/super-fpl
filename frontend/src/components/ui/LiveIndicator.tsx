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
  const sizes = {
    sm: 'w-1.5 h-1.5',
    md: 'w-2 h-2',
    lg: 'w-2.5 h-2.5',
  }

  const textSizes = {
    sm: 'text-[10px]',
    md: 'text-xs',
    lg: 'text-sm',
  }

  return (
    <span className={`inline-flex items-center gap-1.5 ${className}`}>
      <span className="relative flex">
        <span
          className={`
            ${sizes[size]} rounded-full bg-red-500
            animate-pulse-dot
          `}
        />
        <span
          className={`
            absolute inset-0 ${sizes[size]} rounded-full bg-red-500
            animate-ping opacity-75
          `}
        />
      </span>
      {showText && (
        <span
          className={`
            font-display font-bold uppercase tracking-wider text-red-500
            ${textSizes[size]}
          `}
        >
          Live
        </span>
      )}
    </span>
  )
}
