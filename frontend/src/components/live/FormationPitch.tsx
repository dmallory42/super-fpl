import { useMemo, useCallback } from 'react'
import type { XMinsOverrides } from '../../api/client'
import { PitchPlayerCard } from '../pitch/PitchPlayerCard'
import { PitchLayout } from '../pitch/PitchLayout'

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
  fixture?: string
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

interface RowPlayer extends Player {
  teamName: string
  displayPoints: string
  pointsClassName: string
  extraLines: Array<{ text: string; className: string }>
  isSelected: boolean
  showSelectionOutline: boolean
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

  const enrichPlayers = useCallback(
    (list: Player[]): RowPlayer[] =>
      list.map((player) => {
        const teamName = player.team ? (teams[player.team]?.short_name ?? '???') : '???'
        const customXMins = resolveXMins(player.player_id)
        const hasOverride = customXMins !== undefined
        const displayPoints =
          player.effective_points ??
          player.stats?.total_points ??
          player.points ??
          player.predicted_points ??
          0
        const extraLines: Array<{ text: string; className: string }> = []
        if (customXMins !== undefined) {
          extraLines.push({
            text: `${customXMins}m`,
            className: 'text-fpl-green text-xs font-mono mt-0.5',
          })
        }
        if (showEffectiveOwnership && player.effective_ownership) {
          extraLines.push({
            text: `EO: ${player.effective_ownership.effective_ownership.toFixed(0)}%`,
            className: `text-xs mt-0.5 font-mono font-medium ${
              player.effective_ownership.points_swing > 0 ? 'text-destructive' : 'text-fpl-green'
            }`,
          })
        }

        return {
          ...player,
          teamName,
          displayPoints:
            typeof displayPoints === 'number' ? displayPoints.toFixed(1) : String(displayPoints),
          pointsClassName: hasOverride ? 'text-fpl-green' : '',
          extraLines,
          isSelected: selectedPlayer === player.player_id,
          showSelectionOutline:
            selectedPlayer === player.player_id ||
            (newTransferIds.includes(player.player_id) && selectedPlayer !== player.player_id),
        }
      }),
    [newTransferIds, resolveXMins, selectedPlayer, showEffectiveOwnership, teams]
  )

  const handlePlayerClick = useCallback(
    (playerId: number) => onPlayerClick?.(playerId),
    [onPlayerClick]
  )

  // Group starting players by position type
  const startingRows = enrichPlayers(starting)
  const benchRows = enrichPlayers(bench)
  const gk = startingRows.filter((p) => p.element_type === 1)
  const def = startingRows.filter((p) => p.element_type === 2)
  const mid = startingRows.filter((p) => p.element_type === 3)
  const fwd = startingRows.filter((p) => p.element_type === 4)

  const rows = [gk, def, mid, fwd]

  // Pre-compute animation offset (avoids mutable counter in render)
  const rowElements = rows.reduce<{ rows: JSX.Element[][]; offset: number }>(
    (acc, row) => {
      acc.rows.push(
        row.map((player, itemIdx) => (
          <PitchPlayerCard
            key={player.player_id}
            teamId={player.team ?? 0}
            name={player.web_name || `P${player.player_id}`}
            secondaryText={player.fixture ?? player.teamName}
            pointsText={player.displayPoints}
            pointsClassName={player.pointsClassName}
            isCaptain={!!player.is_captain}
            isViceCaptain={!!player.is_vice_captain}
            animationDelay={(acc.offset + itemIdx) * 50}
            isSelected={player.isSelected}
            showSelectionOutline={player.showSelectionOutline}
            onClick={() => handlePlayerClick(player.player_id)}
            extraLines={player.extraLines}
          />
        ))
      )
      acc.offset += row.length
      return acc
    },
    { rows: [], offset: 0 }
  )

  const benchElements = benchRows.map((player, idx) => (
    <PitchPlayerCard
      key={player.player_id}
      teamId={player.team ?? 0}
      name={player.web_name || `P${player.player_id}`}
      secondaryText={player.fixture ?? player.teamName}
      pointsText={player.displayPoints}
      pointsClassName={player.pointsClassName}
      isCaptain={!!player.is_captain}
      isViceCaptain={!!player.is_vice_captain}
      isBench
      animationDelay={(rowElements.offset + idx) * 50}
      isSelected={player.isSelected}
      showSelectionOutline={player.showSelectionOutline}
      onClick={() => handlePlayerClick(player.player_id)}
      extraLines={player.extraLines}
    />
  ))

  return <PitchLayout rows={rowElements.rows} bench={benchElements} variant="formation" />
}
