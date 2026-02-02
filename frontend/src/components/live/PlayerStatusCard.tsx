import { useState } from 'react'

export type PlayerStatus = 'upcoming' | 'playing' | 'finished' | 'unknown'

interface PlayerStatusCardProps {
  playerId: number
  webName: string
  teamName: string
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
  effectiveOwnership,
  animationDelay = 0,
}: PlayerStatusCardProps) {
  const [showTooltip, setShowTooltip] = useState(false)

  const effectivePoints = points * multiplier

  // Status-based styling
  const getJerseyClasses = () => {
    const base = 'w-12 h-12 md:w-14 md:h-14 rounded-lg flex items-center justify-center font-mono font-bold shadow-lg transform transition-all duration-200'

    switch (status) {
      case 'upcoming':
        return `${base} bg-surface border-2 border-dashed border-foreground-dim text-foreground-dim`
      case 'playing':
        return `${base} bg-gradient-to-b from-fpl-green to-emerald-600 text-white animate-pulse-glow ring-2 ring-fpl-green/50`
      case 'finished':
        return `${base} bg-gradient-to-b from-fpl-green to-emerald-600 text-white`
      default:
        return `${base} bg-surface border border-border text-foreground-muted`
    }
  }

  return (
    <div
      className={`flex flex-col items-center ${isBench ? 'opacity-60' : ''} animate-fade-in-up opacity-0`}
      style={{ animationDelay: `${animationDelay}ms` }}
      onMouseEnter={() => setShowTooltip(true)}
      onMouseLeave={() => setShowTooltip(false)}
    >
      {/* Jersey with points */}
      <div className="relative group">
        <div className={getJerseyClasses()}>
          <span className="text-lg">{effectivePoints}</span>
        </div>

        {/* Captain badge */}
        {isCaptain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-yellow-400 to-yellow-600 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow-lg ring-2 ring-yellow-400/50">
            {multiplier === 3 ? 'TC' : 'C'}
          </span>
        )}
        {isViceCaptain && !isCaptain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-gray-300 to-gray-500 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow">
            V
          </span>
        )}

        {/* Playing indicator */}
        {status === 'playing' && (
          <span className="absolute -bottom-1 left-1/2 -translate-x-1/2 bg-fpl-green text-black text-xs px-1.5 py-0.5 rounded font-mono font-bold animate-pulse">
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
      <div className="bg-surface/90 backdrop-blur-sm text-foreground text-xs px-2 py-0.5 rounded mt-1.5 max-w-[70px] truncate font-medium">
        {webName}
      </div>

      {/* Team */}
      <div className="text-white/70 text-xs">{teamName}</div>

      {/* Effective ownership (optional) */}
      {effectiveOwnership !== undefined && effectiveOwnership > 0 && (
        <div className="text-xs mt-0.5 font-mono text-white/90">
          EO: {effectiveOwnership.toFixed(0)}%
        </div>
      )}
    </div>
  )
}
