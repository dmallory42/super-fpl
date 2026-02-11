import { useState } from 'react'
import { TeamShirt } from './TeamShirt'

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
  teamId,
  points,
  multiplier,
  isCaptain,
  isViceCaptain,
  status,
  matchMinute,
  isBench = false,
  effectiveOwnership,
  animationDelay = 0,
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

  // Status-based container styling
  const getContainerClasses = () => {
    const base = 'relative w-14 h-14 md:w-16 md:h-16 flex items-center justify-center'

    switch (status) {
      case 'upcoming':
        return `${base} opacity-50 grayscale`
      case 'playing':
        return `${base} animate-pulse-glow`
      case 'finished':
        return base
      default:
        return `${base} opacity-70`
    }
  }

  return (
    <div
      className={`flex flex-col items-center ${isBench ? 'opacity-60' : ''} animate-fade-in-up opacity-0`}
      style={{ animationDelay: `${animationDelay}ms` }}
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
      {/* Shirt with points */}
      <div className="relative group">
        <div className={getContainerClasses()}>
          {/* Team shirt SVG */}
          <TeamShirt teamId={teamId} size={52} className="drop-shadow-lg" />

          {/* Points overlay - positioned in center of shirt */}
          <div className="absolute inset-0 flex items-center justify-center pt-2">
            <span
              className="text-base md:text-lg font-mono font-bold drop-shadow-[0_2px_2px_rgba(0,0,0,0.8)]"
              style={{ color: '#FFFFFF' }}
            >
              {effectivePoints}
            </span>
          </div>
        </div>

        {/* Playing ring indicator */}
        {status === 'playing' && (
          <div className="absolute inset-0 rounded-full ring-2 ring-fpl-green/50 animate-pulse pointer-events-none" />
        )}

        {/* Captain badge */}
        {isCaptain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-yellow-400 to-yellow-600 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow-lg ring-2 ring-yellow-400/50 z-10">
            {multiplier === 3 ? 'TC' : 'C'}
          </span>
        )}
        {isViceCaptain && !isCaptain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-gray-300 to-gray-500 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow z-10">
            V
          </span>
        )}

        {/* Playing indicator */}
        {status === 'playing' && (
          <span className="absolute -bottom-1 left-1/2 -translate-x-1/2 bg-fpl-green text-black text-xs px-1.5 py-0.5 rounded font-mono font-bold animate-pulse z-10">
            {matchMinute ? `${matchMinute}'` : 'LIVE'}
          </span>
        )}

        {/* Tooltip */}
        {showTooltip && status === 'playing' && matchMinute && (
          <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-surface-elevated border border-border rounded text-xs whitespace-nowrap z-20">
            {matchMinute}' played
          </div>
        )}
      </div>

      {/* Player name */}
      <div className="bg-surface/90 backdrop-blur-sm text-foreground text-[11px] md:text-xs px-1.5 md:px-2 py-0.5 rounded mt-1 max-w-[62px] md:max-w-[70px] truncate font-medium">
        {webName}
      </div>

      {/* Team */}
      <div className="text-white/70 text-[10px] md:text-xs">{teamName}</div>
      <div className="text-[9px] md:text-[10px] text-white/60 font-display uppercase tracking-wide">
        {statusLabel}
      </div>

      {/* Effective ownership (optional) */}
      {effectiveOwnership !== undefined && effectiveOwnership > 0 && (
        <div className="hidden md:block text-xs mt-0.5 font-mono text-white/90">
          EO: {effectiveOwnership.toFixed(0)}%
        </div>
      )}
    </div>
  )
}
