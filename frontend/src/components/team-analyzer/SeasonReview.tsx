import { useMemo } from 'react'
import type { ManagerHistoryResponse } from '../../api/client'
import type { EntryHistory } from '../../types'
import { formatRank } from '../../lib/format'
import { StatPanel, StatPanelGrid } from '../ui/StatPanel'
import { BroadcastCard } from '../ui/BroadcastCard'

interface SeasonReviewProps {
  history: ManagerHistoryResponse | null
}

interface ChipInfo {
  name: string
  displayName: string
  event: number
}

const chipDisplayNames: Record<string, string> = {
  wildcard: 'Wildcard',
  bboost: 'Bench Boost',
  '3xc': 'Triple Captain',
  freehit: 'Free Hit',
}

function RankChart({ gameweeks }: { gameweeks: EntryHistory[] }) {
  if (gameweeks.length === 0) return null

  const ranks = gameweeks.map((gw) => gw.overall_rank)
  const maxRank = Math.max(...ranks)
  const minRank = Math.min(...ranks)
  const range = maxRank - minRank || 1

  const width = 600
  const height = 200
  const padding = { top: 20, right: 40, bottom: 30, left: 60 }
  const chartWidth = width - padding.left - padding.right
  const chartHeight = height - padding.top - padding.bottom

  const points = gameweeks.map((gw, i) => {
    const x = padding.left + (i / (gameweeks.length - 1 || 1)) * chartWidth
    const y = padding.top + ((gw.overall_rank - minRank) / range) * chartHeight
    return { x, y, rank: gw.overall_rank, event: gw.event }
  })

  const pathData = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ')
  const fillPath = `${pathData} L ${points[points.length - 1].x} ${height - padding.bottom} L ${padding.left} ${height - padding.bottom} Z`

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
      <defs>
        <linearGradient id="rankGradient" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="hsl(var(--fpl-green))" stopOpacity="0.3" />
          <stop offset="100%" stopColor="hsl(var(--fpl-green))" stopOpacity="0" />
        </linearGradient>
      </defs>

      {/* Grid lines */}
      <line
        x1={padding.left}
        y1={padding.top}
        x2={padding.left}
        y2={height - padding.bottom}
        className="stroke-border"
      />
      <line
        x1={padding.left}
        y1={height - padding.bottom}
        x2={width - padding.right}
        y2={height - padding.bottom}
        className="stroke-border"
      />

      {/* Gradient fill */}
      <path d={fillPath} fill="url(#rankGradient)" />

      {/* Line */}
      <path
        d={pathData}
        fill="none"
        className="stroke-fpl-green"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {/* Points */}
      {points.map((p, i) => (
        <g key={i}>
          <circle cx={p.x} cy={p.y} r={4} className="fill-fpl-green" />
          <circle cx={p.x} cy={p.y} r={2} className="fill-background" />
        </g>
      ))}

      {/* Y-axis labels */}
      <text
        x={padding.left - 10}
        y={padding.top + 4}
        textAnchor="end"
        className="fill-foreground-muted text-xs font-mono"
      >
        {formatRank(minRank)}
      </text>
      <text
        x={padding.left - 10}
        y={height - padding.bottom}
        textAnchor="end"
        className="fill-foreground-muted text-xs font-mono"
      >
        {formatRank(maxRank)}
      </text>

      {/* X-axis labels */}
      <text
        x={padding.left}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-xs font-mono"
      >
        1
      </text>
      <text
        x={width - padding.right}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-xs font-mono"
      >
        {gameweeks.length}
      </text>
    </svg>
  )
}

function PointsChart({ gameweeks }: { gameweeks: EntryHistory[] }) {
  if (gameweeks.length === 0) return null

  const points = gameweeks.map((gw) => gw.points)
  const maxPoints = Math.max(...points)
  const minPoints = Math.min(...points)
  const avgPoints = points.reduce((a, b) => a + b, 0) / points.length
  const range = maxPoints - minPoints || 1

  const width = 600
  const height = 200
  const padding = { top: 20, right: 40, bottom: 30, left: 60 }
  const chartWidth = width - padding.left - padding.right
  const chartHeight = height - padding.top - padding.bottom

  const chartPoints = gameweeks.map((gw, i) => {
    const x = padding.left + (i / (gameweeks.length - 1 || 1)) * chartWidth
    const y = padding.top + chartHeight - ((gw.points - minPoints) / range) * chartHeight
    return { x, y, points: gw.points, event: gw.event }
  })

  const pathData = chartPoints.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ')
  const avgY = padding.top + chartHeight - ((avgPoints - minPoints) / range) * chartHeight

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
      <defs>
        <linearGradient id="pointsGradient" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="hsl(var(--fpl-green))" stopOpacity="0.3" />
          <stop offset="100%" stopColor="hsl(var(--fpl-green))" stopOpacity="0" />
        </linearGradient>
      </defs>

      {/* Grid */}
      <line
        x1={padding.left}
        y1={padding.top}
        x2={padding.left}
        y2={height - padding.bottom}
        className="stroke-border"
      />
      <line
        x1={padding.left}
        y1={height - padding.bottom}
        x2={width - padding.right}
        y2={height - padding.bottom}
        className="stroke-border"
      />

      {/* Average line */}
      <line
        x1={padding.left}
        y1={avgY}
        x2={width - padding.right}
        y2={avgY}
        className="stroke-foreground-dim"
        strokeDasharray="4 4"
        strokeWidth="1"
      />
      <text
        x={width - padding.right + 5}
        y={avgY + 4}
        className="fill-foreground-dim text-xs font-mono"
      >
        avg
      </text>

      {/* Area fill */}
      <path
        d={`${pathData} L ${chartPoints[chartPoints.length - 1].x} ${height - padding.bottom} L ${padding.left} ${height - padding.bottom} Z`}
        fill="url(#pointsGradient)"
      />

      {/* Line */}
      <path
        d={pathData}
        fill="none"
        className="stroke-fpl-green"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {/* Points - highlight max and min */}
      {chartPoints.map((p, i) => {
        const isMax = p.points === maxPoints
        const isMin = p.points === minPoints
        return (
          <g key={i}>
            <circle
              cx={p.x}
              cy={p.y}
              r={isMax || isMin ? 5 : 3}
              className={isMax ? 'fill-fpl-green' : isMin ? 'fill-destructive' : 'fill-fpl-green'}
            />
            {(isMax || isMin) && (
              <text
                x={p.x}
                y={p.y - 10}
                textAnchor="middle"
                className={`text-xs font-mono ${isMax ? 'fill-fpl-green' : 'fill-destructive'}`}
              >
                {p.points}
              </text>
            )}
          </g>
        )
      })}

      {/* Y-axis labels */}
      <text
        x={padding.left - 10}
        y={padding.top + 4}
        textAnchor="end"
        className="fill-foreground-muted text-xs font-mono"
      >
        {maxPoints}
      </text>
      <text
        x={padding.left - 10}
        y={height - padding.bottom}
        textAnchor="end"
        className="fill-foreground-muted text-xs font-mono"
      >
        {minPoints}
      </text>

      {/* X-axis */}
      <text
        x={padding.left}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-xs font-mono"
      >
        1
      </text>
      <text
        x={width - padding.right}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-xs font-mono"
      >
        {gameweeks.length}
      </text>
    </svg>
  )
}

function SeasonStats({ gameweeks }: { gameweeks: EntryHistory[] }) {
  const stats = useMemo(() => {
    if (gameweeks.length === 0) return null

    const points = gameweeks.map((gw) => gw.points)
    const lastGW = gameweeks[gameweeks.length - 1]
    const firstGW = gameweeks[0]

    const totalPoints = lastGW.total_points
    const overallRank = lastGW.overall_rank
    const bestGW = Math.max(...points)
    const worstGW = Math.min(...points)
    const avgPoints = points.reduce((a, b) => a + b, 0) / points.length

    const bestGWEvent = gameweeks.find((gw) => gw.points === bestGW)?.event
    const worstGWEvent = gameweeks.find((gw) => gw.points === worstGW)?.event

    const totalTransfers = gameweeks.reduce((sum, gw) => sum + gw.event_transfers, 0)
    const totalHits = gameweeks.reduce((sum, gw) => sum + gw.event_transfers_cost, 0)
    const totalBenchPoints = gameweeks.reduce((sum, gw) => sum + gw.points_on_bench, 0)

    return {
      totalPoints,
      overallRank,
      bestGW,
      worstGW,
      avgPoints,
      bestGWEvent,
      worstGWEvent,
      totalTransfers,
      totalHits,
      totalBenchPoints,
      startRank: firstGW.overall_rank,
      endRank: lastGW.overall_rank,
      startValue: firstGW.value,
      endValue: lastGW.value,
    }
  }, [gameweeks])

  if (!stats) return null

  return (
    <StatPanelGrid className="grid-cols-2 md:grid-cols-4">
      <StatPanel label="Total Points" value={stats.totalPoints} highlight animationDelay={0} />
      <StatPanel label="Overall Rank" value={formatRank(stats.overallRank)} animationDelay={50} />
      <StatPanel
        label="Best GW"
        value={stats.bestGW}
        trend="up"
        subValue={`GW${stats.bestGWEvent}`}
        animationDelay={100}
      />
      <StatPanel
        label="Worst GW"
        value={stats.worstGW}
        trend="down"
        subValue={`GW${stats.worstGWEvent}`}
        animationDelay={150}
      />
      <StatPanel label="Avg Per GW" value={stats.avgPoints.toFixed(1)} animationDelay={200} />
      <StatPanel label="Total Transfers" value={stats.totalTransfers} animationDelay={250} />
      <StatPanel
        label="Hit Points"
        value={stats.totalHits > 0 ? `-${stats.totalHits}` : '0'}
        animationDelay={300}
      />
      <StatPanel label="Bench Points" value={stats.totalBenchPoints} animationDelay={350} />
    </StatPanelGrid>
  )
}

function SeasonInsights({ gameweeks }: { gameweeks: EntryHistory[] }) {
  const insights = useMemo(() => {
    if (gameweeks.length < 2) return null

    const firstGW = gameweeks[0]
    const lastGW = gameweeks[gameweeks.length - 1]
    const points = gameweeks.map((gw) => gw.points)
    const avgPoints = points.reduce((a, b) => a + b, 0) / points.length

    // Rank movement
    const rankChange = firstGW.overall_rank - lastGW.overall_rank
    const rankChangePercent = ((rankChange / firstGW.overall_rank) * 100).toFixed(1)
    const rankImproved = rankChange > 0

    // Consistency (standard deviation)
    const variance = points.reduce((sum, p) => sum + Math.pow(p - avgPoints, 2), 0) / points.length
    const stdDev = Math.sqrt(variance)
    const consistencyLabel =
      stdDev < 10
        ? 'Very Consistent'
        : stdDev < 15
          ? 'Consistent'
          : stdDev < 20
            ? 'Variable'
            : 'Unpredictable'

    // Value change
    const valueChange = (lastGW.value - firstGW.value) / 10

    // Transfer efficiency
    const totalTransfers = gameweeks.reduce((sum, gw) => sum + gw.event_transfers, 0)
    const totalHits = gameweeks.reduce((sum, gw) => sum + gw.event_transfers_cost, 0)
    const hitsPerTransfer = totalTransfers > 0 ? (totalHits / totalTransfers).toFixed(1) : '0'

    // Bench management
    const benchPoints = gameweeks.map((gw) => gw.points_on_bench)
    const avgBenchPoints = benchPoints.reduce((a, b) => a + b, 0) / benchPoints.length
    const highBenchWeeks = gameweeks.filter((gw) => gw.points_on_bench >= 10).length

    // Best/worst rank achieved
    const ranks = gameweeks.map((gw) => gw.overall_rank)
    const bestRank = Math.min(...ranks)
    const worstRank = Math.max(...ranks)

    return {
      rankChange,
      rankChangePercent,
      rankImproved,
      startRank: firstGW.overall_rank,
      endRank: lastGW.overall_rank,
      consistencyLabel,
      stdDev: stdDev.toFixed(1),
      valueChange,
      hitsPerTransfer,
      avgBenchPoints: avgBenchPoints.toFixed(1),
      highBenchWeeks,
      bestRank,
      worstRank,
    }
  }, [gameweeks])

  if (!insights) return null

  return (
    <BroadcastCard title="Season Insights" animationDelay={100}>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Rank Movement */}
        <div className="p-4 bg-surface-elevated rounded-lg">
          <div className="text-xs font-display uppercase tracking-wider text-foreground-muted mb-2">
            Rank Movement
          </div>
          <div className="flex items-baseline gap-2">
            <span
              className={`text-2xl font-mono font-bold ${insights.rankImproved ? 'text-fpl-green' : 'text-destructive'}`}
            >
              {insights.rankImproved ? '↑' : '↓'} {Math.abs(Number(insights.rankChangePercent))}%
            </span>
          </div>
          <div className="text-xs text-foreground-dim mt-1">
            {formatRank(insights.startRank)} → {formatRank(insights.endRank)}
          </div>
        </div>

        {/* Consistency */}
        <div className="p-4 bg-surface-elevated rounded-lg">
          <div className="text-xs font-display uppercase tracking-wider text-foreground-muted mb-2">
            Consistency
          </div>
          <div className="text-lg font-bold text-foreground">{insights.consistencyLabel}</div>
          <div className="text-xs text-foreground-dim mt-1">σ = {insights.stdDev} pts</div>
        </div>

        {/* Team Value */}
        <div className="p-4 bg-surface-elevated rounded-lg">
          <div className="text-xs font-display uppercase tracking-wider text-foreground-muted mb-2">
            Value Change
          </div>
          <div
            className={`text-2xl font-mono font-bold ${insights.valueChange >= 0 ? 'text-fpl-green' : 'text-destructive'}`}
          >
            {insights.valueChange >= 0 ? '+' : ''}£{insights.valueChange.toFixed(1)}m
          </div>
        </div>

        {/* Bench Management */}
        <div className="p-4 bg-surface-elevated rounded-lg">
          <div className="text-xs font-display uppercase tracking-wider text-foreground-muted mb-2">
            Bench Management
          </div>
          <div className="text-lg font-mono font-bold text-foreground">
            {insights.avgBenchPoints} avg
          </div>
          <div className="text-xs text-foreground-dim mt-1">
            {insights.highBenchWeeks} weeks with 10+ pts
          </div>
        </div>
      </div>

      {/* Additional stats row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 pt-4 border-t border-border">
        <div className="text-center">
          <div className="text-xs text-foreground-muted">Best Rank</div>
          <div className="font-mono font-bold text-fpl-green">{formatRank(insights.bestRank)}</div>
        </div>
        <div className="text-center">
          <div className="text-xs text-foreground-muted">Worst Rank</div>
          <div className="font-mono font-bold text-foreground-muted">
            {formatRank(insights.worstRank)}
          </div>
        </div>
        <div className="text-center">
          <div className="text-xs text-foreground-muted">Hit Rate</div>
          <div className="font-mono font-bold text-foreground">
            {insights.hitsPerTransfer} pts/transfer
          </div>
        </div>
        <div className="text-center">
          <div className="text-xs text-foreground-muted">High Bench Weeks</div>
          <div className="font-mono font-bold text-foreground">{insights.highBenchWeeks}</div>
        </div>
      </div>
    </BroadcastCard>
  )
}

function GameweekTable({ gameweeks }: { gameweeks: EntryHistory[] }) {
  // Calculate previous ranks for movement indicators
  const gwWithMovement = gameweeks.map((gw, i) => ({
    ...gw,
    prevRank: i > 0 ? gameweeks[i - 1].overall_rank : null,
    rankMovement: i > 0 ? gameweeks[i - 1].overall_rank - gw.overall_rank : 0,
  }))

  return (
    <BroadcastCard title="Gameweek Breakdown" animationDelay={300}>
      <div className="overflow-x-auto -mx-4 px-4">
        <table className="table-broadcast w-auto mx-auto">
          <thead>
            <tr>
              <th className="text-left">GW</th>
              <th className="text-center">Pts</th>
              <th className="text-center">Total</th>
              <th className="text-center">Rank</th>
              <th className="text-center">Move</th>
              <th className="text-center">TF</th>
              <th className="text-center">Hits</th>
              <th className="text-center">Bench</th>
            </tr>
          </thead>
          <tbody>
            {[...gwWithMovement].reverse().map((gw) => {
              const hasHit = gw.event_transfers_cost > 0
              const highBench = gw.points_on_bench >= 10

              return (
                <tr
                  key={gw.event}
                  className={hasHit ? 'bg-destructive/5' : highBench ? 'bg-fpl-green/5' : ''}
                >
                  <td className="font-display text-foreground">GW{gw.event}</td>
                  <td className="text-center font-mono text-fpl-green font-medium">{gw.points}</td>
                  <td className="text-center font-mono text-foreground-muted">{gw.total_points}</td>
                  <td className="text-center font-mono text-foreground-muted">
                    {formatRank(gw.overall_rank)}
                  </td>
                  <td className="text-center font-mono text-sm">
                    {gw.rankMovement > 0 && <span className="text-fpl-green">↑</span>}
                    {gw.rankMovement < 0 && <span className="text-destructive">↓</span>}
                    {gw.rankMovement === 0 && gw.prevRank !== null && (
                      <span className="text-foreground-dim">-</span>
                    )}
                  </td>
                  <td className="text-center font-mono text-foreground-muted">
                    {gw.event_transfers}
                  </td>
                  <td className="text-center font-mono text-destructive">
                    {gw.event_transfers_cost > 0 ? `-${gw.event_transfers_cost}` : '-'}
                  </td>
                  <td
                    className={`text-center font-mono ${highBench ? 'text-fpl-green font-medium' : 'text-foreground-muted'}`}
                  >
                    {gw.points_on_bench}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </BroadcastCard>
  )
}

function ChipsTimeline({ chips }: { chips: ChipInfo[] }) {
  return (
    <BroadcastCard title="Chips Used" animationDelay={400}>
      {chips.length === 0 ? (
        <p className="text-foreground-muted text-sm">No chips used yet</p>
      ) : (
        <div className="flex flex-wrap gap-3">
          {chips.map((chip, i) => (
            <div
              key={i}
              className="bg-gradient-to-r from-fpl-green to-emerald-600 rounded-lg px-4 py-2 shadow-lg"
            >
              <div className="text-white font-display text-sm uppercase tracking-wider">
                {chip.displayName}
              </div>
              <div className="text-white/70 text-xs font-mono">GW {chip.event}</div>
            </div>
          ))}
        </div>
      )}
    </BroadcastCard>
  )
}

export function SeasonReview({ history }: SeasonReviewProps) {
  const processedChips: ChipInfo[] = useMemo(() => {
    if (!history?.chips) return []
    return history.chips.map((chip) => ({
      name: chip.name,
      displayName: chipDisplayNames[chip.name] || chip.name,
      event: chip.event,
    }))
  }, [history?.chips])

  if (!history || !history.current || history.current.length === 0) {
    return (
      <BroadcastCard>
        <div className="py-8 text-center">
          <p className="text-foreground-muted">No season data available</p>
        </div>
      </BroadcastCard>
    )
  }

  return (
    <div className="space-y-6">
      <SeasonStats gameweeks={history.current} />
      <SeasonInsights gameweeks={history.current} />

      {/* Charts side by side on larger screens */}
      <div className="grid md:grid-cols-2 gap-6">
        <BroadcastCard title="Rank Progression" animationDelay={200}>
          <RankChart gameweeks={history.current} />
          <p className="text-xs text-foreground-dim text-center mt-2">Lower is better</p>
        </BroadcastCard>
        <BroadcastCard title="Points Per Gameweek" animationDelay={250}>
          <PointsChart gameweeks={history.current} />
        </BroadcastCard>
      </div>

      <GameweekTable gameweeks={history.current} />
      <ChipsTimeline chips={processedChips} />
    </div>
  )
}
