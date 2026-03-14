import { useState, useMemo, useRef, useEffect, useCallback, useLayoutEffect, memo } from 'react'
import type { PlayerMultiWeekPrediction, XMinsOverrides, FixtureOpponent } from '../../api/client'
import { BroadcastCard } from '../ui/BroadcastCard'
import { PositionBadge } from '../common/PositionBadge'
import { FormInput } from '../ui/form'

interface PlayerExplorerProps {
  players: PlayerMultiWeekPrediction[]
  gameweeks: number[]
  teamsMap: Map<number, string>
  effectiveOwnership?: Record<string, number>
  xMinsOverrides?: XMinsOverrides
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
      className={`w-14 px-1 py-0.5 text-center text-xs border bg-surface-elevated        ${hasOverride ? 'border-tt-green/60 text-tt-green' : 'border-border/30 text-foreground-muted'}
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
  if (pts >= 8) return 'text-tt-green font-bold bg-tt-green/30'
  if (pts >= 6) return 'text-tt-green bg-tt-green/20'
  if (pts >= 4) return 'text-tt-green/80 bg-tt-green/10'
  if (pts >= 2) return 'text-foreground-muted bg-tt-green/5'
  return 'text-foreground-dim'
}

interface ExplorerRowProps {
  playerId: number
  position: number
  webName: string
  teamShort: string
  nowCost: number
  effectiveOwnership: number | null
  baseXMins: number
  committedXMins: number | undefined
  hasOverride: boolean
  totalPoints: number
  gwValues: Array<{ gw: number; pts: number }>
  totalPredicted: number
  onXMinsChange?: (playerId: number, xMins: number, gameweek?: number) => void
  onPlayerClick?: (playerId: number) => void
}

const ExplorerRow = memo(
  function ExplorerRow({
    playerId,
    position,
    webName,
    teamShort,
    nowCost,
    effectiveOwnership,
    baseXMins,
    committedXMins,
    hasOverride,
    totalPoints,
    gwValues,
    totalPredicted,
    onXMinsChange,
    onPlayerClick,
  }: ExplorerRowProps) {
    return (
      <tr
        data-player-id={playerId}
        className="border-b border-border/20 hover:bg-surface-hover/50 transition-colors"
      >
        <td className="px-3 py-1.5 sticky left-0 bg-surface z-10">
          <PositionBadge elementType={position} />
        </td>
        <td
          className={`px-3 py-1.5 font-body font-medium text-foreground sticky left-12 bg-surface z-10 ${onPlayerClick ? 'cursor-pointer hover:text-tt-green transition-colors' : ''}`}
          onClick={onPlayerClick ? () => onPlayerClick(playerId) : undefined}
        >
          {webName}
        </td>
        <td className="px-2 py-1.5 font-body text-foreground-muted">{teamShort}</td>
        <td className="px-2 py-1.5 text-right text-foreground-muted">
          £{(nowCost / 10).toFixed(1)}m
        </td>
        <td className="px-2 py-1.5 text-right text-foreground-muted">
          {effectiveOwnership != null ? `${effectiveOwnership.toFixed(0)}%` : '-'}
        </td>
        <td className="px-1 py-0.5 text-center">
          <XMinsInput
            playerId={playerId}
            baseXMins={baseXMins}
            committedValue={committedXMins}
            onChange={onXMinsChange}
          />
        </td>
        <td className="px-2 py-1.5 text-right text-foreground-muted">{totalPoints}</td>
        {gwValues.map(({ gw, pts }) => (
          <td
            key={gw}
            className={`px-2 py-1.5 text-right text-xs ${getHeatClass(pts)} ${hasOverride ? 'italic' : ''}`}
          >
            {pts.toFixed(1)}
          </td>
        ))}
        <td
          className={`px-3 py-1.5 text-right font-bold text-tt-green ${hasOverride ? 'italic' : ''}`}
        >
          {totalPredicted.toFixed(1)}
        </td>
      </tr>
    )
  },
  (prev, next) => {
    if (prev.playerId !== next.playerId) return false
    if (prev.position !== next.position) return false
    if (prev.webName !== next.webName) return false
    if (prev.teamShort !== next.teamShort) return false
    if (prev.nowCost !== next.nowCost) return false
    if (prev.effectiveOwnership !== next.effectiveOwnership) return false
    if (prev.baseXMins !== next.baseXMins) return false
    if (prev.committedXMins !== next.committedXMins) return false
    if (prev.hasOverride !== next.hasOverride) return false
    if (prev.totalPoints !== next.totalPoints) return false
    if (prev.totalPredicted !== next.totalPredicted) return false
    if (prev.onXMinsChange !== next.onXMinsChange) return false
    if (prev.onPlayerClick !== next.onPlayerClick) return false

    if (prev.gwValues.length !== next.gwValues.length) return false
    for (let i = 0; i < prev.gwValues.length; i++) {
      if (prev.gwValues[i].gw !== next.gwValues[i].gw) return false
      if (prev.gwValues[i].pts !== next.gwValues[i].pts) return false
    }

    return true
  }
)

export function PlayerExplorer({
  players,
  gameweeks,
  teamsMap,
  effectiveOwnership,
  xMinsOverrides,
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
  const tableBodyRef = useRef<HTMLTableSectionElement | null>(null)
  const previousRowTopsRef = useRef<Map<number, number>>(new Map())

  // Pre-compute effective points from API-recalculated predictions.
  const effectivePoints = useMemo(() => {
    const map = new Map<number, { perGw: Record<number, number>; total: number }>()
    for (const player of players) {
      const perGw: Record<number, number> = {}
      let total = 0
      for (const gw of gameweeks) {
        const hasFixture = !!fixtures?.[player.team]?.[gw]?.length
        if (!hasFixture) {
          perGw[gw] = 0
          continue
        }
        perGw[gw] = player.predictions[gw] ?? 0
        total += perGw[gw]
      }
      map.set(player.player_id, { perGw, total: Math.round(total * 10) / 10 })
    }
    return map
  }, [players, gameweeks, fixtures])

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
        aVal = effectivePoints.get(a.player_id)?.perGw[gw] ?? 0
        bVal = effectivePoints.get(b.player_id)?.perGw[gw] ?? 0
      } else if (sortField === 'now_cost') {
        aVal = a.now_cost
        bVal = b.now_cost
      } else if (sortField === 'total_points') {
        aVal = a.total_points
        bVal = b.total_points
      } else {
        aVal = effectivePoints.get(a.player_id)?.total ?? 0
        bVal = effectivePoints.get(b.player_id)?.total ?? 0
      }

      if (typeof aVal === 'string' && typeof bVal === 'string') {
        return sortDir === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal)
      }

      const numA = typeof aVal === 'number' ? aVal : 0
      const numB = typeof bVal === 'number' ? bVal : 0
      return sortDir === 'asc' ? numA - numB : numB - numA
    })
  }, [
    players,
    positionFilter,
    searchQuery,
    sortField,
    sortDir,
    effectiveOwnership,
    effectivePoints,
  ])

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
  const visibleOrderSignature = useMemo(
    () => visiblePlayers.map((player) => player.player_id).join(','),
    [visiblePlayers]
  )

  useLayoutEffect(() => {
    const tbody = tableBodyRef.current
    if (!tbody || typeof window === 'undefined') return
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

    const rows = Array.from(tbody.querySelectorAll<HTMLTableRowElement>('tr[data-player-id]'))
    if (!rows.length) return

    const nextTops = new Map<number, number>()
    rows.forEach((row) => {
      const playerId = Number(row.dataset.playerId)
      if (!Number.isFinite(playerId)) return
      nextTops.set(playerId, row.getBoundingClientRect().top)
    })

    // Skip animation on first paint.
    if (previousRowTopsRef.current.size === 0) {
      previousRowTopsRef.current = nextTops
      return
    }

    rows.forEach((row) => {
      const playerId = Number(row.dataset.playerId)
      if (!Number.isFinite(playerId)) return
      const previousTop = previousRowTopsRef.current.get(playerId)
      const nextTop = nextTops.get(playerId)
      if (previousTop === undefined || nextTop === undefined) return

      const deltaY = previousTop - nextTop
      if (Math.abs(deltaY) < 1) return

      row.style.transition = 'none'
      row.style.transform = `translateY(${deltaY}px)`
      row.style.willChange = 'transform'

      requestAnimationFrame(() => {
        row.style.transition = 'transform 220ms cubic-bezier(0.2, 0, 0, 1)'
        row.style.transform = 'translateY(0)'
      })

      const cleanup = () => {
        row.style.transition = ''
        row.style.willChange = ''
        row.removeEventListener('transitionend', cleanup)
      }
      row.addEventListener('transitionend', cleanup)
    })

    previousRowTopsRef.current = nextTops
  }, [visibleOrderSignature])

  return (
    <BroadcastCard accentColor="magenta">
      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        <FormInput
          type="text"
          value={searchQuery}
          onChange={(e) => {
            setSearchQuery(e.target.value)
            setVisibleCount(50)
          }}
          placeholder="Search players..."
          className="flex-1 sm:max-w-xs"
        />
        <div className="flex gap-1.5">
          {positions.map((pos) => (
            <button
              key={pos.label}
              onClick={() => {
                setPositionFilter(pos.value)
                setVisibleCount(50)
              }}
              className={`px-3 py-1.5text-xs uppercase tracking-wider transition-colors ${
                positionFilter === pos.value
                  ? 'bg-tt-green/20 text-tt-green border border-tt-green/40'
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
              <th className="text-left px-3 py-2 text-xs uppercase tracking-wider text-foreground-muted sticky left-0 bg-surface z-10 w-12">
                Pos
              </th>
              <th
                className="text-left px-3 py-2 text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground sticky left-12 bg-surface z-10 min-w-[120px]"
                onClick={() => handleSort('web_name')}
              >
                Player
                <SortIndicator field="web_name" />
              </th>
              <th className="text-left px-2 py-2 text-xs uppercase tracking-wider text-foreground-muted w-14">
                Team
              </th>
              <th
                className="text-right px-2 py-2 text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-16"
                onClick={() => handleSort('now_cost')}
              >
                Price
                <SortIndicator field="now_cost" />
              </th>
              <th
                className="text-right px-2 py-2 text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-14"
                onClick={() => handleSort('eo')}
              >
                EO%
                <SortIndicator field="eo" />
              </th>
              <th className="text-center px-2 py-2 text-xs uppercase tracking-wider text-foreground-muted w-16">
                xMins
              </th>
              <th
                className="text-right px-2 py-2 text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-12"
                onClick={() => handleSort('total_points')}
              >
                Pts
                <SortIndicator field="total_points" />
              </th>
              {gameweeks.map((gw) => (
                <th
                  key={gw}
                  className="text-right px-2 py-2 text-xs uppercase tracking-wider text-foreground-muted cursor-pointer hover:text-foreground w-13"
                  onClick={() => handleSort(`gw_${gw}`)}
                >
                  GW{gw}
                  <SortIndicator field={`gw_${gw}`} />
                </th>
              ))}
              <th
                className="text-right px-3 py-2 text-xs uppercase tracking-wider text-tt-green cursor-pointer hover:text-tt-green/80 w-16"
                onClick={() => handleSort('total_predicted')}
              >
                Total
                <SortIndicator field="total_predicted" />
              </th>
            </tr>
          </thead>
          <tbody ref={tableBodyRef}>
            {visiblePlayers.map((player) => {
              const baseXMins =
                player.expected_mins?.[gameweeks[0]] ?? player.expected_mins_if_fit ?? 90
              const rawOverride = xMinsOverrides?.[player.player_id]
              // Overrides are always per-GW objects now — show first GW value in input
              const gwOverride =
                typeof rawOverride === 'object' && rawOverride !== null ? rawOverride : null
              const firstGwValue = gwOverride ? gwOverride[gameweeks[0]] : undefined
              const hasOverride = gwOverride != null
              const gwValues = gameweeks.map((gw) => ({
                gw,
                pts: effectivePoints.get(player.player_id)?.perGw[gw] ?? 0,
              }))
              const totalPredicted = effectivePoints.get(player.player_id)?.total ?? 0
              return (
                <ExplorerRow
                  key={player.player_id}
                  playerId={player.player_id}
                  position={player.position}
                  webName={player.web_name}
                  teamShort={teamsMap.get(player.team) ?? ''}
                  nowCost={player.now_cost}
                  effectiveOwnership={effectiveOwnership?.[String(player.player_id)] ?? null}
                  baseXMins={baseXMins}
                  committedXMins={firstGwValue}
                  hasOverride={hasOverride}
                  totalPoints={player.total_points}
                  gwValues={gwValues}
                  totalPredicted={totalPredicted}
                  onXMinsChange={onXMinsChange}
                  onPlayerClick={onPlayerClick}
                />
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
