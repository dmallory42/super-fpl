import { useState } from 'react'

export type PlayerStatus = 'upcoming' | 'playing' | 'finished' | 'unknown'

interface PlayerStatusCardProps {
  playerId: number
  webName: string
  teamName: string
  teamId: number
  points: number
  multiplier: number
  isCaptain: boolean
  isViceCaptain: boolean
  status: PlayerStatus
  matchMinute?: number
  isBench?: boolean
  effectiveOwnership?: number
  animationDelay?: number
}

export function PlayerStatusCard({
  webName,
  teamName,
  points,
  multiplier,
  isCaptain,
  isViceCaptain,
  status,
  matchMinute,
  isBench = false,
}: PlayerStatusCardProps) {
  const [showTooltip, setShowTooltip] = useState(false)

  const effectivePoints = points * multiplier
  const statusLabel =
    status === 'playing'
      ? matchMinute
        ? `Live ${matchMinute}'`
        : 'Live'
      : status === 'finished'
        ? 'Finished'
        : status === 'upcoming'
          ? 'Upcoming'
          : 'Status unknown'

  const borderColor = isBench
    ? 'border-tt-dim'
    : status === 'playing'
      ? 'border-tt-green'
      : 'border-[#006600]'

  const textOpacity = isBench ? 'opacity-60' : ''

  return (
    <div
      className={`flex flex-col items-center ${textOpacity}`}
      onMouseEnter={() => setShowTooltip(true)}
      onMouseLeave={() => setShowTooltip(false)}
      onFocus={() => setShowTooltip(true)}
      onBlur={() => setShowTooltip(false)}
      onClick={() => setShowTooltip((prev) => !prev)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          setShowTooltip((prev) => !prev)
        }
      }}
      tabIndex={0}
      aria-label={`${webName} (${teamName}), ${effectivePoints} points, ${statusLabel}`}
    >
      <div
        className={`relative border ${borderColor} bg-black px-2 py-1 text-center min-w-[88px] md:min-w-[100px]`}
      >
        {/* Points + captain row */}
        <div className="flex items-center justify-center gap-1">
          <span className="text-base md:text-lg font-bold text-tt-yellow">{effectivePoints}</span>
          {isCaptain && (
            <span className="text-tt-yellow text-sm font-bold">
              {multiplier === 3 ? '(TC)' : '(C)'}
            </span>
          )}
          {isViceCaptain && !isCaptain && (
            <span className="text-tt-cyan text-sm font-bold">(V)</span>
          )}
        </div>

        {/* Player name */}
        <div className="text-tt-white text-sm truncate max-w-[84px] md:max-w-[96px]">
          {webName}
        </div>

        {/* Team + status */}
        <div className="flex items-center justify-center gap-1 text-sm">
          <span className="text-tt-cyan">{teamName}</span>
          {status === 'playing' && <span className="text-tt-red animate-blink">●</span>}
        </div>

        {/* Playing indicator */}
        {status === 'playing' && matchMinute && (
          <div className="text-tt-green text-sm font-bold">{matchMinute}'</div>
        )}

        {/* Tooltip */}
        {showTooltip && status === 'playing' && matchMinute && (
          <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-black border border-tt-dim text-sm whitespace-nowrap z-20 text-tt-white">
            {matchMinute}' played
          </div>
        )}
      </div>
    </div>
  )
}
