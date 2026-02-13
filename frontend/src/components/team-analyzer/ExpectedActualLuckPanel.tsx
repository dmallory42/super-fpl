import { useMemo, useState } from 'react'
import type { ManagerSeasonAnalysisResponse } from '../../api/client'

type BenchmarkMode = 'overall' | 'top_10k' | 'league_median'

interface RowData {
  gameweek: number
  actual: number
  expected: number
  luckDelta: number
  benchmarkPoints: number | null
  benchmarkDelta: number | null
  cumulativeLuck: number
  cumulativeBenchmarkDelta: number
}

interface ExpectedActualLuckPanelProps {
  seasonAnalysis: ManagerSeasonAnalysisResponse
  leagueMedianByGw?: Map<number, number>
}

function formatSigned(value: number | null, precision = 1): string {
  if (value === null) return '—'
  const prefix = value > 0 ? '+' : ''
  return `${prefix}${value.toFixed(precision)}`
}

function toSeriesMap(series?: Array<{ gameweek: number; points: number | null }>): Map<number, number | null> {
  const map = new Map<number, number | null>()
  if (!series) return map
  for (const row of series) {
    map.set(row.gameweek, row.points)
  }
  return map
}

export function buildExpectedActualRows(
  seasonAnalysis: ManagerSeasonAnalysisResponse,
  benchmarkMode: BenchmarkMode,
  leagueMedianByGw?: Map<number, number>
): RowData[] {
  const overallMap = toSeriesMap(seasonAnalysis.benchmarks?.overall)
  const top10kMap = toSeriesMap(seasonAnalysis.benchmarks?.top_10k)

  let cumulativeLuck = 0
  let cumulativeBenchmarkDelta = 0

  return seasonAnalysis.gameweeks.map((gw) => {
    let benchmarkPoints: number | null = null
    if (benchmarkMode === 'overall') {
      benchmarkPoints = overallMap.get(gw.gameweek) ?? null
    } else if (benchmarkMode === 'top_10k') {
      benchmarkPoints = top10kMap.get(gw.gameweek) ?? null
    } else {
      benchmarkPoints = leagueMedianByGw?.get(gw.gameweek) ?? null
    }

    const benchmarkDelta = benchmarkPoints === null ? null : gw.actual_points - benchmarkPoints
    cumulativeLuck += gw.luck_delta
    if (benchmarkDelta !== null) {
      cumulativeBenchmarkDelta += benchmarkDelta
    }

    return {
      gameweek: gw.gameweek,
      actual: gw.actual_points,
      expected: gw.expected_points,
      luckDelta: gw.luck_delta,
      benchmarkPoints,
      benchmarkDelta,
      cumulativeLuck,
      cumulativeBenchmarkDelta,
    }
  })
}

function CumulativeChart({ rows }: { rows: RowData[] }) {
  if (rows.length === 0) return null

  const values = rows.flatMap((row) => [row.cumulativeLuck, row.cumulativeBenchmarkDelta])
  const maxValue = Math.max(...values)
  const minValue = Math.min(...values)
  const range = maxValue - minValue || 1

  const width = 760
  const height = 220
  const padding = { top: 20, right: 20, bottom: 34, left: 48 }
  const chartWidth = width - padding.left - padding.right
  const chartHeight = height - padding.top - padding.bottom

  const toPoint = (index: number, value: number) => {
    const x = padding.left + (index / (rows.length - 1 || 1)) * chartWidth
    const y = padding.top + chartHeight - ((value - minValue) / range) * chartHeight
    return { x, y }
  }

  const luckPath = rows
    .map((row, idx) => {
      const p = toPoint(idx, row.cumulativeLuck)
      return `${idx === 0 ? 'M' : 'L'} ${p.x} ${p.y}`
    })
    .join(' ')

  const benchmarkPath = rows
    .map((row, idx) => {
      const p = toPoint(idx, row.cumulativeBenchmarkDelta)
      return `${idx === 0 ? 'M' : 'L'} ${p.x} ${p.y}`
    })
    .join(' ')

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="w-full" aria-label="Cumulative Trend Chart">
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

      <path d={luckPath} fill="none" className="stroke-fpl-green" strokeWidth="2" strokeLinecap="round" />
      <path
        d={benchmarkPath}
        fill="none"
        className="stroke-highlight"
        strokeWidth="2"
        strokeDasharray="6 4"
        strokeLinecap="round"
      />

      <text
        x={padding.left}
        y={height - 10}
        textAnchor="middle"
        className="fill-foreground-muted text-xs font-mono"
      >
        GW{rows[0].gameweek}
      </text>
      <text
        x={width - padding.right}
        y={height - 10}
        textAnchor="middle"
        className="fill-foreground-muted text-xs font-mono"
      >
        GW{rows[rows.length - 1].gameweek}
      </text>
    </svg>
  )
}

export function ExpectedActualLuckPanel({
  seasonAnalysis,
  leagueMedianByGw,
}: ExpectedActualLuckPanelProps) {
  const [benchmarkMode, setBenchmarkMode] = useState<BenchmarkMode>('overall')

  const hasLeagueMedian = (leagueMedianByGw?.size ?? 0) > 0
  const hasTop10k = (seasonAnalysis.benchmarks?.top_10k?.length ?? 0) > 0

  const rows = useMemo(
    () => buildExpectedActualRows(seasonAnalysis, benchmarkMode, leagueMedianByGw),
    [benchmarkMode, leagueMedianByGw, seasonAnalysis]
  )

  const totals = useMemo(() => {
    if (rows.length === 0) return null
    const last = rows[rows.length - 1]
    return {
      cumulativeLuck: last.cumulativeLuck,
      cumulativeBenchmarkDelta: last.cumulativeBenchmarkDelta,
    }
  }, [rows])

  if (rows.length === 0 || totals === null) {
    return null
  }

  return (
    <div className="space-y-4" data-testid="expected-actual-luck-panel">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h4 className="font-display text-sm uppercase tracking-wider text-foreground">
            Expected vs Actual
          </h4>
          <p className="text-xs text-foreground-dim">
            Compare per-GW points against expected model output and benchmark tiers.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <label htmlFor="season-benchmark-select" className="text-xs text-foreground-muted uppercase">
            Benchmark
          </label>
          <select
            id="season-benchmark-select"
            className="input-broadcast h-10 min-w-[210px]"
            value={benchmarkMode}
            onChange={(event) => setBenchmarkMode(event.target.value as BenchmarkMode)}
          >
            <option value="overall">Overall</option>
            {hasTop10k && <option value="top_10k">Top 10K</option>}
            {hasLeagueMedian && <option value="league_median">League Median</option>}
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div className="rounded-lg border border-fpl-green/30 bg-fpl-green/5 p-3">
          <div className="text-xs uppercase text-foreground-muted">Cumulative Luck</div>
          <div className="font-mono text-2xl text-foreground">
            {formatSigned(totals.cumulativeLuck, 1)}
          </div>
        </div>
        <div className="rounded-lg border border-highlight/30 bg-highlight/5 p-3">
          <div className="text-xs uppercase text-foreground-muted">Cumulative vs Benchmark</div>
          <div className="font-mono text-2xl text-foreground">
            {formatSigned(totals.cumulativeBenchmarkDelta, 1)}
          </div>
        </div>
      </div>

      <CumulativeChart rows={rows} />

      <div className="overflow-x-auto -mx-4">
        <table className="table-broadcast min-w-[840px]" data-testid="expected-actual-table">
          <thead>
            <tr>
              <th>GW</th>
              <th className="text-right">Actual</th>
              <th className="text-right">Expected</th>
              <th className="text-right">Luck</th>
              <th className="text-right">Benchmark</th>
              <th className="text-right">Delta</th>
              <th className="text-right">Cum Luck</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.gameweek}>
                <td className="font-mono text-foreground-muted">{row.gameweek}</td>
                <td className="text-right font-mono">{row.actual.toFixed(1)}</td>
                <td className="text-right font-mono">{row.expected.toFixed(1)}</td>
                <td className="text-right font-mono">{formatSigned(row.luckDelta, 1)}</td>
                <td className="text-right font-mono">
                  {row.benchmarkPoints === null ? '—' : row.benchmarkPoints.toFixed(1)}
                </td>
                <td className="text-right font-mono">{formatSigned(row.benchmarkDelta, 1)}</td>
                <td className="text-right font-mono">{formatSigned(row.cumulativeLuck, 1)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
