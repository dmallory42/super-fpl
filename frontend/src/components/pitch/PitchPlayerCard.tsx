interface PitchPlayerCardProps {
  teamId: number
  name: string
  secondaryText: string
  metaText?: string
  pointsText?: string
  pointsClassName?: string
  isCaptain?: boolean
  isViceCaptain?: boolean
  isBench?: boolean
  isSelected?: boolean
  showSelectionOutline?: boolean
  pulseShirt?: boolean
  animationDelay?: number
  onClick?: () => void
  extraLines?: Array<{ text: string; className: string }>
}

export function PitchPlayerCard({
  name,
  secondaryText,
  metaText,
  pointsText,
  pointsClassName = '',
  isCaptain = false,
  isViceCaptain = false,
  isBench = false,
  isSelected = false,
  showSelectionOutline = false,
  onClick,
  extraLines = [],
}: PitchPlayerCardProps) {
  const borderColor = isBench ? 'border-tt-dim' : 'border-[#006600]'
  const textOpacity = isBench ? 'opacity-60' : ''

  return (
    <div className={`relative ${isSelected ? 'scale-110' : ''} ${textOpacity}`}>
      {showSelectionOutline && <div className="absolute -inset-1 border border-tt-cyan z-0" />}

      <div
        className={`relative z-10 border ${borderColor} bg-black px-2 py-1 text-center min-w-[88px] md:min-w-[100px] ${onClick ? 'cursor-pointer' : ''}`}
        onClick={onClick}
      >
        {/* Points + captain/vice row */}
        <div className="flex items-center justify-center gap-1">
          {pointsText !== undefined && (
            <span className={`text-base md:text-lg font-bold text-tt-yellow ${pointsClassName}`}>
              {pointsText}
            </span>
          )}
          {isCaptain && <span className="text-tt-yellow text-sm font-bold">(C)</span>}
          {isViceCaptain && !isCaptain && (
            <span className="text-tt-cyan text-sm font-bold">(V)</span>
          )}
        </div>

        {/* Player name */}
        <div className="text-tt-white text-sm truncate max-w-[88px] md:max-w-[100px]">{name}</div>

        {/* Team/secondary */}
        <div className="text-tt-cyan text-sm">{secondaryText}</div>

        {/* Meta */}
        {metaText && <div className="text-tt-dim text-sm">{metaText}</div>}

        {/* Extra lines */}
        {extraLines.map((line, idx) => (
          <div key={idx} className={line.className}>
            {line.text}
          </div>
        ))}
      </div>
    </div>
  )
}
