import { useMemo } from 'react'
import type { ManagerHistoryResponse, ManagerSeasonAnalysisResponse } from '../../api/client'
import type { EntryHistory } from '../../types'
import { formatRank } from '../../lib/format'
import { StatPanel, StatPanelGrid } from '../ui/StatPanel'
import { BroadcastCard } from '../ui/BroadcastCard'

interface SeasonReviewProps {
  history: ManagerHistoryResponse | null
  benchmarks?: ManagerSeasonAnalysisResponse['benchmarks']
}

interface ChipInfo {
  name: string
  displayName: string
  event: number
}

const POINTS_NEUTRAL_RANGE = 5

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
          <stop offset="0%" stopColor="hsl(var(--tt-green))" stopOpacity="0.3" />
          <stop offset="100%" stopColor="hsl(var(--tt-green))" stopOpacity="0" />
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
        className="stroke-tt-green"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {/* Points */}
      {points.map((p, i) => (
        <g key={i}>
          <circle cx={p.x} cy={p.y} r={4} className="fill-tt-green" />
          <circle cx={p.x} cy={p.y} r={2} className="fill-background" />
        </g>
      ))}

      {/* Y-axis labels */}
      <text
        x={padding.left - 10}
        y={padding.top + 4}
        textAnchor="end"
        className="fill-foreground-muted text-sm"
      >
        {formatRank(minRank)}
      </text>
      <text
        x={padding.left - 10}
        y={height - padding.bottom}
        textAnchor="end"
        className="fill-foreground-muted text-sm"
      >
        {formatRank(maxRank)}
      </text>

      {/* X-axis labels */}
      <text
        x={padding.left}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-sm"
      >
        1
      </text>
      <text
        x={width - padding.right}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-sm"
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
          <stop offset="0%" stopColor="hsl(var(--tt-green))" stopOpacity="0.3" />
          <stop offset="100%" stopColor="hsl(var(--tt-green))" stopOpacity="0" />
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
      <text x={width - padding.right + 5} y={avgY + 4} className="fill-foreground-dim text-sm">
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
        className="stroke-tt-green"
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
              className={isMax ? 'fill-tt-green' : isMin ? 'fill-destructive' : 'fill-tt-green'}
            />
            {(isMax || isMin) && (
              <text
                x={p.x}
                y={p.y - 10}
                textAnchor="middle"
                className={`text-sm ${isMax ? 'fill-tt-green' : 'fill-destructive'}`}
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
        className="fill-foreground-muted text-sm"
      >
        {maxPoints}
      </text>
      <text
        x={padding.left - 10}
        y={height - padding.bottom}
        textAnchor="end"
        className="fill-foreground-muted text-sm"
      >
        {minPoints}
      </text>

      {/* X-axis */}
      <text
        x={padding.left}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-sm"
      >
        1
      </text>
      <text
        x={width - padding.right}
        y={height - 8}
        textAnchor="middle"
        className="fill-foreground-muted text-sm"
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
      <StatPanel label="Total Points" value={stats.totalPoints} highlight />
      <StatPanel label="Overall Rank" value={formatRank(stats.overallRank)} />
      <StatPanel
        label="Best GW"
        value={stats.bestGW}
        trend="up"
        subValue={`GW${stats.bestGWEvent}`}
      />
      <StatPanel
        label="Worst GW"
        value={stats.worstGW}
        trend="down"
        subValue={`GW${stats.worstGWEvent}`}
      />
      <StatPanel label="Avg Per GW" value={stats.avgPoints.toFixed(1)} />
      <StatPanel label="Total Transfers" value={stats.totalTransfers} />
      <StatPanel label="Hit Points" value={stats.totalHits > 0 ? `-${stats.totalHits}` : '0'} />
      <StatPanel label="Bench Points" value={stats.totalBenchPoints} />
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
        <div className="p-4 bg-surface-elevated ">
          <div className="text-sm uppercase text-foreground-muted mb-2">Rank Movement</div>
          <div className="flex items-baseline gap-2">
            <span
              className={`text-2xl font-bold ${insights.rankImproved ? 'text-tt-green' : 'text-destructive'}`}
            >
              {insights.rankImproved ? '↑' : '↓'} {Math.abs(Number(insights.rankChangePercent))}%
            </span>
          </div>
          <div className="text-sm text-foreground-dim mt-1">
            {formatRank(insights.startRank)} → {formatRank(insights.endRank)}
          </div>
        </div>

        {/* Consistency */}
        <div className="p-4 bg-surface-elevated ">
          <div className="text-sm uppercase text-foreground-muted mb-2">Consistency</div>
          <div className="text-lg font-bold text-foreground">{insights.consistencyLabel}</div>
          <div className="text-sm text-foreground-dim mt-1">σ = {insights.stdDev} pts</div>
        </div>

        {/* Team Value */}
        <div className="p-4 bg-surface-elevated ">
          <div className="text-sm uppercase text-foreground-muted mb-2">Value Change</div>
          <div
            className={`text-2xl font-bold ${insights.valueChange >= 0 ? 'text-tt-green' : 'text-destructive'}`}
          >
            {insights.valueChange >= 0 ? '+' : ''}£{insights.valueChange.toFixed(1)}m
          </div>
        </div>

        {/* Bench Management */}
        <div className="p-4 bg-surface-elevated ">
          <div className="text-sm uppercase text-foreground-muted mb-2">Bench Management</div>
          <div className="text-lg font-bold text-foreground">{insights.avgBenchPoints} avg</div>
          <div className="text-sm text-foreground-dim mt-1">
            {insights.highBenchWeeks} weeks with 10+ pts
          </div>
        </div>
      </div>

      {/* Additional stats row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 pt-4 border-t border-border">
        <div className="text-center">
          <div className="text-sm text-foreground-muted">Best Rank</div>
          <div className="font-bold text-tt-green">{formatRank(insights.bestRank)}</div>
        </div>
        <div className="text-center">
          <div className="text-sm text-foreground-muted">Worst Rank</div>
          <div className="font-bold text-foreground-muted">{formatRank(insights.worstRank)}</div>
        </div>
        <div className="text-center">
          <div className="text-sm text-foreground-muted">Hit Rate</div>
          <div className="font-bold text-foreground">{insights.hitsPerTransfer} pts/transfer</div>
        </div>
        <div className="text-center">
          <div className="text-sm text-foreground-muted">High Bench Weeks</div>
          <div className="font-bold text-foreground">{insights.highBenchWeeks}</div>
        </div>
      </div>
    </BroadcastCard>
  )
}

function pointsDeltaClass(value: number): string {
  if (value > POINTS_NEUTRAL_RANGE) return 'text-tt-green'
  if (value < -POINTS_NEUTRAL_RANGE) return 'text-destructive'
  return 'text-foreground'
}

function pointsDeltaBadgeClass(value: number): string {
  if (value > POINTS_NEUTRAL_RANGE) return 'border-tt-green/30 bg-tt-green/10 text-tt-green'
  if (value < -POINTS_NEUTRAL_RANGE)
    return 'border-destructive/30 bg-destructive/10 text-destructive'
  return 'border-border bg-surface text-foreground-muted'
}

function GameweekTable({
  gameweeks,
  benchmarks,
}: {
  gameweeks: EntryHistory[]
  benchmarks?: ManagerSeasonAnalysisResponse['benchmarks']
}) {
  const overallByGw = useMemo(
    () => new Map((benchmarks?.overall ?? []).map((row) => [row.gameweek, row.points])),
    [benchmarks?.overall]
  )

  // Calculate previous ranks for movement indicators
  const gwWithMovement = gameweeks.map((gw, i) => ({
    ...gw,
    prevRank: i > 0 ? gameweeks[i - 1].overall_rank : null,
    rankMovement: i > 0 ? gameweeks[i - 1].overall_rank - gw.overall_rank : 0,
  }))

  return (
    <BroadcastCard title="Gameweek Breakdown" animationDelay={300}>
      <div className="overflow-x-auto -mx-4">
        <table className="table-broadcast w-full min-w-[760px] table-fixed">
          <colgroup>
            <col className="w-[12%]" />
            <col className="w-[22%]" />
            <col className="w-[14%]" />
            <col className="w-[14%]" />
            <col className="w-[8%]" />
            <col className="w-[8%]" />
            <col className="w-[10%]" />
            <col className="w-[12%]" />
          </colgroup>
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
              const overallPoints = overallByGw.get(gw.event)
              const overallDelta =
                typeof overallPoints === 'number' ? gw.points - overallPoints : null

              return (
                <tr key={gw.event} className={hasHit ? 'bg-destructive/5' : ''}>
                  <td className="text-foreground">GW{gw.event}</td>
                  <td
                    className={`text-center font-medium ${
                      overallDelta === null ? 'text-tt-green' : pointsDeltaClass(overallDelta)
                    }`}
                    title={
                      overallDelta === null
                        ? 'No average data'
                        : `vs avg: ${overallDelta > 0 ? '+' : ''}${overallDelta.toFixed(1)} (neutral within ±${POINTS_NEUTRAL_RANGE})`
                    }
                  >
                    <div className="flex flex-col items-center gap-1">
                      <span>{gw.points}</span>
                      {typeof overallPoints === 'number' && (
                        <span
                          className={`border px-1.5 py-0.5 text-sm leading-none ${pointsDeltaBadgeClass(overallDelta ?? 0)}`}
                        >
                          AVG {Math.round(overallPoints)}
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="text-center text-foreground-muted">{gw.total_points}</td>
                  <td className="text-center text-foreground-muted">
                    {formatRank(gw.overall_rank)}
                  </td>
                  <td className="text-center text-sm">
                    {gw.rankMovement > 0 && <span className="text-tt-green">↑</span>}
                    {gw.rankMovement < 0 && <span className="text-destructive">↓</span>}
                    {gw.rankMovement === 0 && gw.prevRank !== null && (
                      <span className="text-foreground-dim">-</span>
                    )}
                  </td>
                  <td className="text-center text-foreground-muted">{gw.event_transfers}</td>
                  <td
                    className={`text-center ${
                      gw.event_transfers_cost > 0 ? 'text-destructive' : 'text-foreground-dim'
                    }`}
                  >
                    {gw.event_transfers_cost > 0 ? `-${gw.event_transfers_cost}` : '-'}
                  </td>
                  <td className="text-center text-foreground-muted">{gw.points_on_bench}</td>
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
    <BroadcastCard title="Chips Used">
      {chips.length === 0 ? (
        <p className="text-foreground-muted text-base">No chips used yet</p>
      ) : (
        <div className="flex flex-wrap gap-3">
          {chips.map((chip, i) => (
            <div key={i} className="bg-tt-green px-4 py-2">
              <div className="text-white text-sm uppercase">{chip.displayName}</div>
              <div className="text-white/70 text-sm">GW {chip.event}</div>
            </div>
          ))}
        </div>
      )}
    </BroadcastCard>
  )
}

export function SeasonReview({ history, benchmarks }: SeasonReviewProps) {
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
        <BroadcastCard title="Rank Progression">
          <RankChart gameweeks={history.current} />
          <p className="text-sm text-foreground-dim text-center mt-2">Lower is better</p>
        </BroadcastCard>
        <BroadcastCard title="Points Per Gameweek">
          <PointsChart gameweeks={history.current} />
        </BroadcastCard>
      </div>

      <GameweekTable gameweeks={history.current} benchmarks={benchmarks} />
      <ChipsTimeline chips={processedChips} />
    </div>
  )
}
