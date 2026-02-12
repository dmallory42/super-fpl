import { TeamShirt } from '../live/TeamShirt'

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
  teamId,
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
  pulseShirt = false,
  animationDelay = 0,
  onClick,
  extraLines = [],
}: PitchPlayerCardProps) {
  return (
    <div
      className={`
        w-20 flex flex-col items-center animate-fade-in-up opacity-0 relative
        ${isBench ? 'opacity-60' : ''}
        ${isSelected ? 'scale-110' : ''}
      `}
      style={{ animationDelay: `${animationDelay}ms` }}
    >
      {showSelectionOutline && (
        <div className="absolute -inset-1 rounded-lg border border-fpl-green/60 z-0" />
      )}

      <div className={`relative group z-10 ${onClick ? 'cursor-pointer' : ''}`} onClick={onClick}>
        <div
          className={`
            relative w-16 h-16 md:w-20 md:h-20 flex items-center justify-center
            transform transition-transform duration-200 group-hover:scale-110
            ${pulseShirt ? 'animate-pulse-glow' : ''}
          `}
        >
          <TeamShirt teamId={teamId} size={64} className="drop-shadow-lg" />

          {pointsText !== undefined && (
            <div className="absolute inset-0 flex items-center justify-center pt-2">
              <span
                className={`text-lg font-mono font-bold drop-shadow-[0_2px_2px_rgba(0,0,0,0.8)] ${pointsClassName}`}
                style={{ color: pointsClassName ? undefined : '#FFFFFF' }}
              >
                {pointsText}
              </span>
            </div>
          )}
        </div>

        {isCaptain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-yellow-400 to-yellow-600 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow-lg ring-2 ring-yellow-400/50 z-10">
            C
          </span>
        )}
        {isViceCaptain && !isCaptain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-gray-300 to-gray-500 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow z-10">
            V
          </span>
        )}
      </div>

      <div className="mt-1.5 text-center">
        <div className="bg-surface/90 backdrop-blur-sm px-2 py-0.5 rounded text-xs font-semibold text-foreground truncate max-w-[80px] md:max-w-[100px]">
          {name}
        </div>
        <div className="text-xs text-white/70 mt-0.5">{secondaryText}</div>
        {metaText && <div className="text-xs text-white/50 font-mono">{metaText}</div>}
        {extraLines.map((line, idx) => (
          <div key={idx} className={line.className}>
            {line.text}
          </div>
        ))}
      </div>
    </div>
  )
}
