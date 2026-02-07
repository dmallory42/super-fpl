import { useMemo, useState, useCallback, memo } from 'react'
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
  editable?: boolean
  xMinsOverrides?: Record<number, number>
  onXMinsChange?: (playerId: number, xMins: number) => void
  // Transfer mode props
  transferMode?: boolean
  selectedForTransfer?: number | null
  newTransferIds?: number[]
  onPlayerClick?: (playerId: number) => void
}

// Default expected minutes by position when not provided
const DEFAULT_EXPECTED_MINS: Record<number, number> = {
  1: 90, // GK
  2: 85, // DEF
  3: 80, // MID
  4: 75, // FWD
}

// FPL scoring thresholds
const MINS_THRESHOLD = 60
const APPEARANCE_POINTS_FULL = 2
const APPEARANCE_POINTS_PARTIAL = 1
const CS_POINTS: Record<number, number> = {
  1: 4, // GK
  2: 4, // DEF
  3: 1, // MID
  4: 0, // FWD
}

// Estimate clean sheet points from total predicted points
// Higher for defenders, lower for mids, zero for forwards
function estimateCSPoints(position: number, totalPoints: number): number {
  const csPointsForPosition = CS_POINTS[position] ?? 0
  if (csPointsForPosition === 0) return 0

  // Estimate CS probability from total points (rough heuristic)
  // Defenders typically have 0.3-0.8 pts from CS, mids have 0.05-0.2
  if (position === 1 || position === 2) {
    // GK/DEF: estimate ~15-25% of points from CS
    return Math.min(csPointsForPosition * 0.4, totalPoints * 0.2)
  } else if (position === 3) {
    // MID: estimate ~3-5% of points from CS
    return Math.min(csPointsForPosition * 0.3, totalPoints * 0.05)
  }
  return 0
}

export function FormationPitch({
  players,
  teams,
  showEffectiveOwnership = false,
  editable = false,
  xMinsOverrides = {},
  onXMinsChange,
  transferMode = false,
  selectedForTransfer = null,
  newTransferIds = [],
  onPlayerClick,
}: FormationPitchProps) {
  const [editingPlayer, setEditingPlayer] = useState<number | null>(null)

  const { starting, bench } = useMemo(() => {
    const sorted = [...players].sort((a, b) => a.position - b.position)

    const starting = sorted.filter((p) => p.position <= 11)
    const bench = sorted.filter((p) => p.position > 11)

    return { starting, bench }
  }, [players])

  // Calculate adjusted points based on xMins overrides
  // Accounts for FPL thresholds: 60+ mins for full appearance and CS eligibility
  const getAdjustedPoints = (player: Player): number => {
    const basePoints = player.predicted_points ?? player.points ?? 0
    const baseXMins = player.expected_mins ?? DEFAULT_EXPECTED_MINS[player.element_type ?? 3] ?? 80
    const customXMins = xMinsOverrides[player.player_id]
    const position = player.element_type ?? 3

    if (customXMins === undefined || customXMins === baseXMins) {
      return basePoints
    }

    // Estimate baseline component breakdown (approximate)
    // These estimates assume a typical prediction structure
    const baseAppearancePts = baseXMins >= MINS_THRESHOLD ? 1.8 : 0.9
    const baseCsPts = baseXMins >= MINS_THRESHOLD ? estimateCSPoints(position, basePoints) : 0
    const baseOtherPts = Math.max(0, basePoints - baseAppearancePts - baseCsPts)

    // Calculate new components based on adjusted xMins
    let newAppearancePts: number
    if (customXMins === 0) {
      newAppearancePts = 0
    } else if (customXMins >= MINS_THRESHOLD) {
      // Full appearance points, scaled slightly by time
      newAppearancePts = APPEARANCE_POINTS_FULL * Math.min(1, customXMins / 90)
    } else {
      // Partial appearance points
      newAppearancePts = APPEARANCE_POINTS_PARTIAL * (customXMins / MINS_THRESHOLD)
    }

    // Clean sheet: only eligible at 60+ mins
    const newCsPts = customXMins >= MINS_THRESHOLD ? baseCsPts * (customXMins / baseXMins) : 0

    // Other points (goals, assists, bonus) scale roughly linearly
    const timeRatio = baseXMins > 0 ? customXMins / baseXMins : 0
    const newOtherPts = baseOtherPts * timeRatio

    const adjustedPoints = newAppearancePts + newCsPts + newOtherPts
    return Math.round(adjustedPoints * 10) / 10
  }

  // Calculate totals with overrides
  const { startingTotal } = useMemo(() => {
    let startingTotal = 0

    players.forEach((player) => {
      const adjustedPoints = getAdjustedPoints(player)
      if (player.position <= 11) {
        // Apply captain multiplier for starting XI
        startingTotal += adjustedPoints * (player.is_captain ? 2 : 1)
      }
    })

    return {
      startingTotal: Math.round(startingTotal * 10) / 10,
    }
  }, [players, xMinsOverrides])

  const handleXMinsSubmit = useCallback(
    (playerId: number, value: number) => {
      onXMinsChange?.(playerId, value)
      setEditingPlayer(null)
    },
    [onXMinsChange]
  )

  const handleEditClick = useCallback((playerId: number) => {
    setEditingPlayer(playerId)
  }, [])

  const handleEditClose = useCallback(() => {
    setEditingPlayer(null)
  }, [])

  const handleTransferClick = useCallback(
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

      {/* Adjusted totals display when editable */}
      {editable && Object.keys(xMinsOverrides).length > 0 && (
        <div className="absolute top-2 right-2 bg-surface/90 backdrop-blur-sm rounded px-2 py-1 text-xs z-20">
          <span className="text-foreground-muted">Adjusted: </span>
          <span className="font-mono font-bold text-fpl-green">{startingTotal} pts</span>
        </div>
      )}

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
                      editable={editable}
                      isEditing={editingPlayer === player.player_id}
                      customXMins={xMinsOverrides[player.player_id]}
                      adjustedPoints={getAdjustedPoints(player)}
                      onEditClick={handleEditClick}
                      onXMinsChange={handleXMinsSubmit}
                      onEditClose={handleEditClose}
                      transferMode={transferMode}
                      isSelectedForTransfer={selectedForTransfer === player.player_id}
                      isNewTransfer={newTransferIds.includes(player.player_id)}
                      onTransferClick={handleTransferClick}
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
              editable={editable}
              isEditing={editingPlayer === player.player_id}
              customXMins={xMinsOverrides[player.player_id]}
              adjustedPoints={getAdjustedPoints(player)}
              onEditClick={handleEditClick}
              onXMinsChange={handleXMinsSubmit}
              onEditClose={handleEditClose}
              transferMode={transferMode}
              isSelectedForTransfer={selectedForTransfer === player.player_id}
              isNewTransfer={newTransferIds.includes(player.player_id)}
              onTransferClick={handleTransferClick}
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
  editable?: boolean
  isEditing?: boolean
  customXMins?: number
  adjustedPoints?: number
  onEditClick?: (playerId: number) => void
  onXMinsChange?: (playerId: number, value: number) => void
  onEditClose?: () => void
  // Transfer mode props
  transferMode?: boolean
  isSelectedForTransfer?: boolean
  isNewTransfer?: boolean
  onTransferClick?: (playerId: number) => void
}

const PlayerCard = memo(function PlayerCard({
  player,
  teams,
  showEO = false,
  isBench = false,
  animationDelay = 0,
  editable = false,
  isEditing = false,
  customXMins,
  adjustedPoints,
  onEditClick,
  onXMinsChange,
  onEditClose,
  transferMode = false,
  isSelectedForTransfer = false,
  isNewTransfer = false,
  onTransferClick,
}: PlayerCardProps) {
  const [inputValue, setInputValue] = useState(
    customXMins?.toString() ??
      player.expected_mins?.toString() ??
      DEFAULT_EXPECTED_MINS[player.element_type ?? 3]?.toString() ??
      '80'
  )

  const playerId = player.player_id
  const basePoints = player.stats?.total_points ?? player.points ?? player.predicted_points ?? 0
  // Use adjusted points if available (for editable mode), otherwise base points
  const displayPoints = adjustedPoints ?? player.effective_points ?? basePoints
  const teamName = player.team ? (teams[player.team]?.short_name ?? '???') : '???'
  const teamId = player.team ?? 0
  const hasOverride = customXMins !== undefined

  const handleSubmit = () => {
    const value = parseInt(inputValue, 10)
    if (!isNaN(value) && value >= 0 && value <= 95) {
      onXMinsChange?.(playerId, value)
    } else {
      onEditClose?.()
    }
  }

  const isClickable = transferMode || editable
  const handleClick = () => {
    if (transferMode && onTransferClick) {
      onTransferClick(playerId)
    } else if (editable && onEditClick) {
      onEditClick(playerId)
    }
  }

  return (
    <div
      className={`
        flex flex-col items-center animate-fade-in-up opacity-0 relative
        ${isBench ? 'opacity-60' : ''}
        ${isSelectedForTransfer ? 'scale-110' : ''}
      `}
      style={{ animationDelay: `${animationDelay}ms` }}
    >
      {/* Selection ring for transfer mode */}
      {isSelectedForTransfer && (
        <div className="absolute -inset-2 rounded-lg border-2 border-destructive bg-destructive/20 animate-pulse z-0" />
      )}
      {isNewTransfer && (
        <div className="absolute -inset-2 rounded-lg border-2 border-fpl-green bg-fpl-green/20 z-0" />
      )}

      {/* xMins Editor Popup */}
      {isEditing && (
        <div className="absolute -top-16 left-1/2 -translate-x-1/2 bg-surface border border-border rounded-lg p-2 shadow-lg z-30 min-w-[120px]">
          <div className="text-xs text-foreground-muted mb-1 font-display uppercase tracking-wider">
            Expected Mins
          </div>
          <div className="flex gap-1">
            <input
              type="number"
              min="0"
              max="95"
              value={inputValue}
              onChange={(e) => setInputValue(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleSubmit()
                if (e.key === 'Escape') onEditClose?.()
              }}
              className="w-14 px-2 py-1 text-sm font-mono bg-surface-elevated border border-border rounded focus:outline-none focus:ring-1 focus:ring-fpl-green"
              autoFocus
            />
            <button
              onClick={handleSubmit}
              className="px-2 py-1 text-xs bg-fpl-green text-black rounded font-bold hover:bg-fpl-green/80"
            >
              OK
            </button>
          </div>
          {/* Arrow pointing down */}
          <div className="absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-3 h-3 bg-surface border-r border-b border-border rotate-45" />
        </div>
      )}

      {/* Shirt with points */}
      <div
        className={`relative group ${isClickable ? 'cursor-pointer' : ''} z-10`}
        onClick={isClickable ? handleClick : undefined}
      >
        <div
          className={`
            relative w-14 h-14 md:w-16 md:h-16 flex items-center justify-center
            transform transition-transform duration-200 group-hover:scale-110
          `}
        >
          {/* Team shirt SVG */}
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

        {/* Edit indicator - clickable to edit xMins */}
        {editable && (
          <button
            onClick={(e) => {
              e.stopPropagation()
              onEditClick?.(playerId)
            }}
            className="absolute -bottom-0.5 -right-0.5 bg-surface/90 hover:bg-fpl-green hover:text-black rounded-full w-5 h-5 flex items-center justify-center text-[10px] opacity-0 group-hover:opacity-100 transition-all z-20"
          >
            ✎
          </button>
        )}
      </div>

      {/* Player name */}
      <div className="bg-surface/90 backdrop-blur-sm text-foreground text-xs px-2 py-0.5 rounded mt-1.5 max-w-[80px] md:max-w-[100px] truncate font-medium">
        {player.web_name || `P${player.player_id}`}
      </div>

      {/* Team */}
      <div className="text-white/70 text-xs">{teamName}</div>

      {/* xMins indicator when overridden */}
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
