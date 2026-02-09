import { useMemo, useCallback, memo } from 'react'
import type { XMinsOverrides } from '../../api/client'
import { TeamShirt } from './TeamShirt'

interface Player {
  player_id: number
  web_name?: string
  element_type?: number
  team?: number
  multiplier: number
  is_captain?: boolean
  is_vice_captain?: boolean
  position: number
  points?: number
  predicted_points?: number
  expected_mins?: number
  effective_points?: number
  stats?: {
    total_points?: number
  }
  effective_ownership?: {
    ownership_percent: number
    captain_percent: number
    effective_ownership: number
    points_swing: number
  }
}

interface Team {
  id: number
  short_name: string
}

interface FormationPitchProps {
  players: Player[]
  teams: Record<number, Team>
  showEffectiveOwnership?: boolean
  xMinsOverrides?: XMinsOverrides
  selectedGw?: number
  // Player selection
  selectedPlayer?: number | null
  newTransferIds?: number[]
  onPlayerClick?: (playerId: number) => void
}

export function FormationPitch({
  players,
  teams,
  showEffectiveOwnership = false,
  xMinsOverrides = {},
  selectedGw,
  selectedPlayer = null,
  newTransferIds = [],
  onPlayerClick,
}: FormationPitchProps) {
  const { starting, bench } = useMemo(() => {
    const sorted = [...players].sort((a, b) => a.position - b.position)

    const starting = sorted.filter((p) => p.position <= 11)
    const bench = sorted.filter((p) => p.position > 11)

    return { starting, bench }
  }, [players])

  // Resolve the displayed xMins for a player (used by PlayerCard indicator)
  // Only returns a value when the user has explicitly set an override
  const resolveXMins = (playerId: number): number | undefined => {
    const rawOverride = xMinsOverrides[playerId]
    if (typeof rawOverride === 'number') return rawOverride
    if (typeof rawOverride === 'object' && rawOverride !== null && selectedGw !== undefined) {
      return rawOverride[selectedGw]
    }
    return undefined
  }

  const handlePlayerClick = useCallback(
    (playerId: number) => {
      onPlayerClick?.(playerId)
    },
    [onPlayerClick]
  )

  // Group starting players by position type
  const gk = starting.filter((p) => p.element_type === 1)
  const def = starting.filter((p) => p.element_type === 2)
  const mid = starting.filter((p) => p.element_type === 3)
  const fwd = starting.filter((p) => p.element_type === 4)

  const rows = [gk, def, mid, fwd]

  // Pre-compute animation offset (avoids mutable counter in render)
  const startingCount = rows.reduce((sum, row) => sum + row.length, 0)

  return (
    <div className="pitch-texture rounded-lg p-4 relative overflow-hidden">
      {/* Pitch markings */}
      <div className="absolute inset-4 border-2 border-white/20 rounded pointer-events-none" />
      <div className="absolute left-1/2 top-4 bottom-4 w-px bg-white/10 pointer-events-none" />
      <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 border-2 border-white/10 rounded-full pointer-events-none" />

      {/* Goal area */}
      <div className="absolute bottom-4 left-1/2 -translate-x-1/2 w-32 h-12 border-t-2 border-x-2 border-white/10 rounded-t-lg pointer-events-none" />

      {/* Formation display */}
      <div className="relative z-10 flex flex-col gap-6 py-4">
        {
          rows.reduce<{ elements: JSX.Element[]; offset: number }>(
            (acc, row, rowIndex) => {
              acc.elements.push(
                <div key={rowIndex} className="flex justify-center gap-2 md:gap-4">
                  {row.map((player, itemIdx) => (
                    <PlayerCard
                      key={player.player_id}
                      player={player}
                      teams={teams}
                      showEO={showEffectiveOwnership}
                      animationDelay={(acc.offset + itemIdx) * 50}
                      customXMins={resolveXMins(player.player_id)}
                      isSelected={selectedPlayer === player.player_id}
                      isNewTransfer={newTransferIds.includes(player.player_id)}
                      onPlayerClick={handlePlayerClick}
                    />
                  ))}
                </div>
              )
              acc.offset += row.length
              return acc
            },
            { elements: [], offset: 0 }
          ).elements
        }
      </div>

      {/* Bench */}
      <div className="mt-6 pt-4 border-t-2 border-white/20">
        <div className="flex items-center justify-center gap-2 mb-3">
          <div className="h-px flex-1 bg-gradient-to-r from-transparent to-white/20" />
          <span className="font-display text-xs uppercase tracking-wider text-white/60 px-3">
            Bench
          </span>
          <div className="h-px flex-1 bg-gradient-to-l from-transparent to-white/20" />
        </div>
        <div className="flex justify-center gap-2 md:gap-4 bg-surface/30 rounded-lg py-3 px-4">
          {bench.map((player, idx) => (
            <PlayerCard
              key={player.player_id}
              player={player}
              teams={teams}
              showEO={showEffectiveOwnership}
              isBench
              animationDelay={(startingCount + idx) * 50}
              customXMins={resolveXMins(player.player_id)}
              isSelected={selectedPlayer === player.player_id}
              isNewTransfer={newTransferIds.includes(player.player_id)}
              onPlayerClick={handlePlayerClick}
            />
          ))}
        </div>
      </div>
    </div>
  )
}

interface PlayerCardProps {
  player: Player
  teams: Record<number, Team>
  showEO?: boolean
  isBench?: boolean
  animationDelay?: number
  customXMins?: number
  isSelected?: boolean
  isNewTransfer?: boolean
  onPlayerClick?: (playerId: number) => void
}

const PlayerCard = memo(function PlayerCard({
  player,
  teams,
  showEO = false,
  isBench = false,
  animationDelay = 0,
  customXMins,
  isSelected = false,
  isNewTransfer = false,
  onPlayerClick,
}: PlayerCardProps) {
  const playerId = player.player_id
  const displayPoints =
    player.effective_points ??
    player.stats?.total_points ??
    player.points ??
    player.predicted_points ??
    0
  const teamName = player.team ? (teams[player.team]?.short_name ?? '???') : '???'
  const teamId = player.team ?? 0
  const hasOverride = customXMins !== undefined

  const handleClick = () => {
    onPlayerClick?.(playerId)
  }

  return (
    <div
      className={`
        flex flex-col items-center animate-fade-in-up opacity-0 relative
        ${isBench ? 'opacity-60' : ''}
        ${isSelected ? 'scale-110' : ''}
      `}
      style={{ animationDelay: `${animationDelay}ms` }}
    >
      {/* Selection / new transfer indicator */}
      {(isSelected || (isNewTransfer && !isSelected)) && (
        <div className="absolute -inset-1 rounded-lg border border-fpl-green/60 z-0" />
      )}

      {/* Shirt with points */}
      <div
        className={`relative group ${onPlayerClick ? 'cursor-pointer' : ''} z-10`}
        onClick={handleClick}
      >
        <div
          className={`
            relative w-14 h-14 md:w-16 md:h-16 flex items-center justify-center
            transform transition-transform duration-200 group-hover:scale-110
          `}
        >
          <TeamShirt teamId={teamId} size={56} className="drop-shadow-lg" />

          {/* Points overlay */}
          <div className="absolute inset-0 flex items-center justify-center pt-2">
            <span
              className={`text-lg font-mono font-bold drop-shadow-[0_2px_2px_rgba(0,0,0,0.8)] ${hasOverride ? 'text-fpl-green' : ''}`}
              style={{ color: hasOverride ? undefined : '#FFFFFF' }}
            >
              {typeof displayPoints === 'number' ? displayPoints.toFixed(1) : displayPoints}
            </span>
          </div>
        </div>

        {/* Captain badge */}
        {player.is_captain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-yellow-400 to-yellow-600 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow-lg ring-2 ring-yellow-400/50 z-10">
            C
          </span>
        )}
        {player.is_vice_captain && !player.is_captain && (
          <span className="absolute -top-1 -right-1 bg-gradient-to-br from-gray-300 to-gray-500 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow z-10">
            V
          </span>
        )}
      </div>

      {/* Player name */}
      <div className="bg-surface/90 backdrop-blur-sm text-foreground text-xs px-2 py-0.5 rounded mt-1.5 max-w-[80px] md:max-w-[100px] truncate font-medium">
        {player.web_name || `P${player.player_id}`}
      </div>

      {/* Team */}
      <div className="text-white/70 text-xs">{teamName}</div>

      {/* xMins indicator when user has overridden */}
      {hasOverride && <div className="text-fpl-green text-xs font-mono mt-0.5">{customXMins}m</div>}

      {/* Effective ownership */}
      {showEO && player.effective_ownership && (
        <div
          className={`text-xs mt-0.5 font-mono font-medium ${
            player.effective_ownership.points_swing > 0 ? 'text-destructive' : 'text-fpl-green'
          }`}
        >
          EO: {player.effective_ownership.effective_ownership.toFixed(0)}%
        </div>
      )}
    </div>
  )
})
