import { useState, useMemo, useRef, useEffect, useCallback, memo } from 'react'
import type { PlayerMultiWeekPrediction, XMinsOverrides, FixtureOpponent } from '../../api/client'
import { BroadcastCard } from '../ui/BroadcastCard'
import { PositionBadge } from '../common/PositionBadge'
import { scalePoints } from '../../lib/predictions'

interface PlayerExplorerProps {
  players: PlayerMultiWeekPrediction[]
  gameweeks: number[]
  teamsMap: Map<number, string>
  effectiveOwnership?: Record<string, number>
  xMinsOverrides?: XMinsOverrides
  perGwXMins?: Record<number, Record<number, number>>
  fixtures?: Record<number, Record<number, FixtureOpponent[]>>
  onXMinsChange?: (playerId: number, xMins: number, gameweek?: number) => void
  onResetXMins?: () => void
  onPlayerClick?: (playerId: number) => void
}

/** Self-contained xMins input that manages its own state and debounces updates to the parent. */
const XMinsInput = memo(function XMinsInput({
  playerId,
  baseXMins,
  committedValue,
  onChange,
}: {
  playerId: number
  baseXMins: number
  committedValue: number | undefined
  onChange?: (playerId: number, xMins: number) => void
}) {
  const [localValue, setLocalValue] = useState<string>(
    committedValue != null ? String(committedValue) : ''
  )
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Sync local state when committedValue changes externally (e.g. reset)
  useEffect(() => {
    setLocalValue(committedValue != null ? String(committedValue) : '')
  }, [committedValue])

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const val = e.target.value
      setLocalValue(val)

      if (timerRef.current) clearTimeout(timerRef.current)

      if (!onChange) return

      timerRef.current = setTimeout(() => {
        if (val === '') return
        const num = Math.max(0, Math.min(95, parseInt(val, 10)))
        if (!isNaN(num)) {
          onChange(playerId, num)
        }
      }, 600)
    },
    [onChange, playerId]
  )

  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [])

  const hasOverride = localValue !== ''

  return (
    <input
      type="text"
      inputMode="numeric"
      pattern="[0-9]*"
      value={localValue}
      placeholder={String(baseXMins)}
      onChange={handleChange}
      className={`w-14 px-1 py-0.5 text-center text-xs font-mono rounded border bg-surface-elevated transition-colors
        ${hasOverride ? 'border-fpl-green/60 text-fpl-green' : 'border-border/30 text-foreground-muted'}
      `}
    />
  )
})

type SortField =
  | 'web_name'
  | 'now_cost'
  | 'total_points'
  | 'total_predicted'
  | `gw_${number}`
  | 'eo'
type SortDir = 'asc' | 'desc'

function getHeatClass(pts: number): string {
  if (pts >= 8) return 'text-fpl-green font-bold bg-fpl-green/30'
  if (pts >= 6) return 'text-fpl-green bg-fpl-green/20'
  if (pts >= 4) return 'text-fpl-green/80 bg-fpl-green/10'
  if (pts >= 2) return 'text-foreground-muted bg-fpl-green/5'
  return 'text-foreground-dim'
}

export function PlayerExplorer({
  players,
  gameweeks,
  teamsMap,
  effectiveOwnership,
  xMinsOverrides,
  perGwXMins,
  fixtures,
  onXMinsChange,
  onResetXMins,
  onPlayerClick,
}: PlayerExplorerProps) {
  const [sortField, setSortField] = useState<SortField>('total_predicted')
  const [sortDir, setSortDir] = useState<SortDir>('desc')
  const [positionFilter, setPositionFilter] = useState<number | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [visibleCount, setVisibleCount] = useState(50)

  const filteredAndSorted = useMemo(() => {
    let filtered = players

    if (positionFilter !== null) {
      filtered = filtered.filter((p) => p.position === positionFilter)
    }

    if (searchQuery) {
      const q = searchQuery.toLowerCase()
      filtered = filtered.filter((p) => p.web_name.toLowerCase().includes(q))
    }

    return [...filtered].sort((a, b) => {
      let aVal: number | string
      let bVal: number | string

      if (sortField === 'web_name') {
        aVal = a.web_name.toLowerCase()
        bVal = b.web_name.toLowerCase()
      } else if (sortField === 'eo') {
        aVal = effectiveOwnership?.[String(a.player_id)] ?? 0
        bVal = effectiveOwnership?.[String(b.player_id)] ?? 0
      } else if (sortField.startsWith('gw_')) {
        const gw = parseInt(sortField.slice(3), 10)
        aVal = a.predictions[gw] ?? 0
        bVal = b.predictions[gw] ?? 0
      } else if (sortField === 'now_cost') {
        aVal = a.now_cost
        bVal = b.now_cost
      } else if (sortField === 'total_points') {
        aVal = a.total_points
        bVal = b.total_points
      } else {
        aVal = a.total_predicted
        bVal = b.total_predicted
      }

      if (typeof aVal === 'string' && typeof bVal === 'string') {
        return sortDir === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal)
      }

      const numA = typeof aVal === 'number' ? aVal : 0
      const numB = typeof bVal === 'number' ? bVal : 0
      return sortDir === 'asc' ? numA - numB : numB - numA
    })
  }, [players, positionFilter, searchQuery, sortField, sortDir, effectiveOwnership])

  const handleSort = (field: SortField) => {
    if (field === sortField) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortField(field)
      setSortDir(field === 'web_name' ? 'asc' : 'desc')
    }
  }

  const SortIndicator = ({ field }: { field: SortField }) => {
    if (field !== sortField) return null
    return <span className="ml-0.5">{sortDir === 'asc' ? '↑' : '↓'}</span>
  }

  const positions = [
    { value: null, label: 'All' },
    { value: 1, label: 'GKP' },
    { value: 2, label: 'DEF' },
    { value: 3, label: 'MID' },
    { value: 4, label: 'FWD' },
  ]

  const hasXMinsOverrides = xMinsOverrides && Object.keys(xMinsOverrides).length > 0

  const visiblePlayers = filteredAndSorted.slice(0, visibleCount)

  return (
    <BroadcastCard accentColor="purple" animationDelay={500}>
      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        <input
          type="text"
          value={searchQuery}
          onChange={(e) => {
            setSearchQuery(e.target.value)
            setVisibleCount(50)
          }}
          placeholder="Search players..."
          className="input-broadcast flex-1 sm:max-w-xs"
        />
        <div className="flex gap-1.5">
          {positions.map((pos) => (
            <button
              key={pos.label}
              onClick={() => {
                setPositionFilter(pos.value)
                setVisibleCount(50)
              }}
              className={`px-3 py-1.5 rounded text-xs font-display uppercase tracking-wider transition-colors ${
                positionFilter === pos.value
                  ? 'bg-fpl-green/20 text-fpl-green border border-fpl-green/40'
                  : 'bg-surface-elevated text-foreground-muted border border-transparent hover:bg-surface-hover'
              }`}
            >
              {pos.label}
            </button>
          ))}
        </div>
        {hasXMinsOverrides && onResetXMins && (
          <button onClick={onResetXMins} className="btn-secondary text-xs whitespace-nowrap">
            Reset xMins
          </button>
        )}
      </div>

      {/* Scrollable table */}
      <div className="overflow-x-auto -mx-4">
        <table className="w-full text-sm min-w-[700px]">
          <thead>
            <tr className="border-b border-border/50">
              <th className="text-left px-3 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted sticky left-0 bg-surface z-10 w-12">
                Pos
              </th>
              <th
                className="text-left px-3 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground sticky left-12 bg-surface z-10 min-w-[120px]"
                onClick={() => handleSort('web_name')}
              >
                Player
                <SortIndicator field="web_name" />
              </th>
              <th className="text-left px-2 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-14">
                Team
              </th>
              <th
                className="text-right px-2 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-16"
                onClick={() => handleSort('now_cost')}
              >
                Price
                <SortIndicator field="now_cost" />
              </th>
              <th
                className="text-right px-2 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-14"
                onClick={() => handleSort('eo')}
              >
                EO%
                <SortIndicator field="eo" />
              </th>
              <th className="text-center px-2 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-16">
                xMins
              </th>
              <th
                className="text-right px-2 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-12"
                onClick={() => handleSort('total_points')}
              >
                Pts
                <SortIndicator field="total_points" />
              </th>
              {gameweeks.map((gw) => (
                <th
                  key={gw}
                  className="text-right px-2 py-2 font-display text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-13"
                  onClick={() => handleSort(`gw_${gw}`)}
                >
                  GW{gw}
                  <SortIndicator field={`gw_${gw}`} />
                </th>
              ))}
              <th
                className="text-right px-3 py-2 font-display text-xs uppercase tracking-wider text-fpl-green cursor-pointer hover:text-fpl-green/80 w-16"
                onClick={() => handleSort('total_predicted')}
              >
                Total
                <SortIndicator field="total_predicted" />
              </th>
            </tr>
          </thead>
          <tbody>
            {visiblePlayers.map((player) => {
              const eo = effectiveOwnership?.[String(player.player_id)]
              const baseXMins = player.expected_mins ?? 90
              const rawOverride = xMinsOverrides?.[player.player_id]
              // Overrides are always per-GW objects now — show first GW value in input
              const gwOverride =
                typeof rawOverride === 'object' && rawOverride !== null ? rawOverride : null
              const firstGwValue = gwOverride ? gwOverride[gameweeks[0]] : undefined
              const hasOverride = gwOverride != null

              return (
                <tr
                  key={player.player_id}
                  className="border-b border-border/20 hover:bg-surface-hover/50 transition-colors"
                >
                  <td className="px-3 py-1.5 sticky left-0 bg-surface z-10">
                    <PositionBadge elementType={player.position} />
                  </td>
                  <td
                    className={`px-3 py-1.5 font-body font-medium text-foreground sticky left-12 bg-surface z-10 ${onPlayerClick ? 'cursor-pointer hover:text-fpl-green transition-colors' : ''}`}
                    onClick={onPlayerClick ? () => onPlayerClick(player.player_id) : undefined}
                  >
                    {player.web_name}
                  </td>
                  <td className="px-2 py-1.5 font-body text-foreground-muted">
                    {teamsMap.get(player.team) ?? ''}
                  </td>
                  <td className="px-2 py-1.5 text-right font-mono text-foreground-muted">
                    £{(player.now_cost / 10).toFixed(1)}m
                  </td>
                  <td className="px-2 py-1.5 text-right font-mono text-foreground-muted">
                    {eo != null ? `${eo.toFixed(0)}%` : '-'}
                  </td>
                  <td className="px-1 py-0.5 text-center">
                    <XMinsInput
                      playerId={player.player_id}
                      baseXMins={baseXMins}
                      committedValue={firstGwValue}
                      onChange={onXMinsChange}
                    />
                  </td>
                  <td className="px-2 py-1.5 text-right font-mono text-foreground-muted">
                    {player.total_points}
                  </td>
                  {gameweeks.map((gw) => {
                    // No fixture = blank gameweek, no points
                    const hasFixture = !!fixtures?.[player.team]?.[gw]?.length
                    const ifFitPts = hasFixture
                      ? (player.if_fit_predictions?.[gw] ?? player.predictions[gw] ?? 0)
                      : 0
                    const ifFitMins = player.expected_mins_if_fit ?? baseXMins
                    let effectiveMins: number
                    if (gwOverride && gwOverride[gw] != null) {
                      effectiveMins = gwOverride[gw]
                    } else if (!hasOverride) {
                      effectiveMins =
                        perGwXMins?.[player.player_id]?.[gw] ?? player.expected_mins ?? 0
                    } else {
                      effectiveMins = player.expected_mins ?? 0
                    }
                    const pts = scalePoints(ifFitPts, ifFitMins, effectiveMins)
                    const isScaled = hasOverride || perGwXMins?.[player.player_id] != null
                    return (
                      <td
                        key={gw}
                        className={`px-2 py-1.5 text-right font-mono text-xs rounded-sm ${getHeatClass(pts)} ${isScaled ? 'italic' : ''}`}
                      >
                        {pts.toFixed(1)}
                      </td>
                    )
                  })}
                  <td
                    className={`px-3 py-1.5 text-right font-mono font-bold text-fpl-green ${hasOverride || perGwXMins?.[player.player_id] ? 'italic' : ''}`}
                  >
                    {(() => {
                      const ifFitMins = player.expected_mins_if_fit ?? baseXMins
                      let total = 0
                      for (const gw of gameweeks) {
                        const hasFixture = !!fixtures?.[player.team]?.[gw]?.length
                        const ifFitPts = hasFixture
                          ? (player.if_fit_predictions?.[gw] ?? player.predictions[gw] ?? 0)
                          : 0
                        let effectiveMins: number
                        if (gwOverride && gwOverride[gw] != null) {
                          effectiveMins = gwOverride[gw]
                        } else if (!hasOverride) {
                          effectiveMins =
                            perGwXMins?.[player.player_id]?.[gw] ?? player.expected_mins ?? 0
                        } else {
                          effectiveMins = player.expected_mins ?? 0
                        }
                        total += scalePoints(ifFitPts, ifFitMins, effectiveMins)
                      }
                      return total.toFixed(1)
                    })()}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Footer */}
      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border/50">
        <span className="text-xs text-foreground-muted">
          Showing {visiblePlayers.length} of {filteredAndSorted.length} players
        </span>
        {visibleCount < filteredAndSorted.length && (
          <button onClick={() => setVisibleCount((c) => c + 50)} className="btn-secondary text-xs">
            Show More
          </button>
        )}
      </div>
    </BroadcastCard>
  )
}
