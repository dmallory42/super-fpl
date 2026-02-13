import { useMemo, useState } from 'react'
import type { LeagueSeasonManager } from '../../api/client'

type DecisionMetricKey =
  | 'captain_gains'
  | 'transfer_net_gain'
  | 'hit_cost'
  | 'hit_roi'
  | 'chip_events'

type SortDirection = 'asc' | 'desc'

interface DecisionMetricConfig {
  key: DecisionMetricKey
  label: string
  description: string
  preferredDirection: SortDirection
  formatValue: (value: number | null) => string
}

const METRICS: DecisionMetricConfig[] = [
  {
    key: 'transfer_net_gain',
    label: 'Transfer Net Gain',
    description: 'Cumulative transfer net gain from `decision_quality.transfer_net_gain`.',
    preferredDirection: 'desc',
    formatValue: (value) => formatSigned(value, 1),
  },
  {
    key: 'captain_gains',
    label: 'Captain Gains',
    description: 'Total captain gains from `decision_quality.captain_gains`.',
    preferredDirection: 'desc',
    formatValue: (value) => formatSigned(value, 1),
  },
  {
    key: 'hit_roi',
    label: 'Hit ROI',
    description: 'Return on hit cost from `decision_quality.hit_roi`.',
    preferredDirection: 'desc',
    formatValue: (value) => (value === null ? '—' : value.toFixed(2)),
  },
  {
    key: 'hit_cost',
    label: 'Hit Cost',
    description: 'Total points spent on transfer hits from `decision_quality.hit_cost`.',
    preferredDirection: 'asc',
    formatValue: (value) => (value ?? 0).toFixed(0),
  },
  {
    key: 'chip_events',
    label: 'Chip Events',
    description: 'Number of chip events from `decision_quality.chip_events`.',
    preferredDirection: 'desc',
    formatValue: (value) => (value ?? 0).toFixed(0),
  },
]

function formatSigned(value: number | null, precision: number): string {
  if (value === null) return '—'
  const prefix = value > 0 ? '+' : ''
  return `${prefix}${value.toFixed(precision)}`
}

function getMetricValue(manager: LeagueSeasonManager, metric: DecisionMetricKey): number | null {
  const value = manager.decision_quality[metric]
  if (value === null || value === undefined) {
    return null
  }
  return Number(value)
}

function computeMedian(values: number[]): number {
  if (values.length === 0) return 0
  const sorted = [...values].sort((a, b) => a - b)
  const mid = Math.floor(sorted.length / 2)
  if (sorted.length % 2 === 1) {
    return sorted[mid]
  }
  return (sorted[mid - 1] + sorted[mid]) / 2
}

function compareNullable(
  a: number | null,
  b: number | null,
  direction: SortDirection
): number {
  if (a === null && b === null) return 0
  if (a === null) return 1
  if (b === null) return -1
  const delta = a - b
  return direction === 'asc' ? delta : -delta
}

export function DecisionDeltaModule({ managers }: { managers: LeagueSeasonManager[] }) {
  const [metric, setMetric] = useState<DecisionMetricKey>('transfer_net_gain')
  const metricConfig = METRICS.find((entry) => entry.key === metric) ?? METRICS[0]
  const [direction, setDirection] = useState<SortDirection>(metricConfig.preferredDirection)

  const values = useMemo(() => {
    return managers
      .map((manager) => getMetricValue(manager, metric))
      .filter((value): value is number => value !== null)
  }, [managers, metric])

  const median = useMemo(() => computeMedian(values), [values])

  const rows = useMemo(() => {
    return [...managers].sort((a, b) => {
      const aValue = getMetricValue(a, metric)
      const bValue = getMetricValue(b, metric)
      const valueCmp = compareNullable(aValue, bValue, direction)
      if (valueCmp !== 0) return valueCmp

      const rankCmp = a.rank - b.rank
      if (rankCmp !== 0) return rankCmp

      return a.manager_id - b.manager_id
    })
  }, [direction, managers, metric])

  const handleMetricChange = (nextMetric: DecisionMetricKey) => {
    const nextMetricConfig = METRICS.find((entry) => entry.key === nextMetric) ?? METRICS[0]
    setMetric(nextMetric)
    setDirection(nextMetricConfig.preferredDirection)
  }

  return (
    <div className="space-y-4" data-testid="decision-delta-module">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h4 className="font-display text-sm uppercase tracking-wider text-foreground">
            Decision Delta vs League Median
          </h4>
          <p className="text-xs text-foreground-dim">{metricConfig.description}</p>
        </div>
        <div className="flex items-center gap-2">
          <label htmlFor="decision-metric-select" className="text-xs text-foreground-muted uppercase">
            Metric
          </label>
          <select
            id="decision-metric-select"
            className="input-broadcast h-10 min-w-[210px]"
            value={metric}
            onChange={(event) => handleMetricChange(event.target.value as DecisionMetricKey)}
          >
            {METRICS.map((entry) => (
              <option key={entry.key} value={entry.key}>
                {entry.label}
              </option>
            ))}
          </select>
          <button
            type="button"
            className="btn-secondary h-10"
            onClick={() => setDirection((current) => (current === 'desc' ? 'asc' : 'desc'))}
          >
            {direction === 'desc' ? 'High to Low' : 'Low to High'}
          </button>
        </div>
      </div>

      <div className="text-xs text-foreground-muted">
        League median ({metricConfig.label}):
        <span className="ml-1 font-mono text-foreground">{metricConfig.formatValue(median)}</span>
      </div>

      <div className="overflow-x-auto -mx-4">
        <table className="table-broadcast min-w-[760px]" data-testid="decision-delta-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Manager</th>
              <th>Team</th>
              <th className="text-right">{metricConfig.label}</th>
              <th className="text-right">Delta vs Median</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((manager, idx) => {
              const value = getMetricValue(manager, metric)
              const delta = value === null ? null : value - median
              return (
                <tr key={manager.manager_id}>
                  <td className="font-mono text-foreground-muted">{idx + 1}</td>
                  <td className="font-medium text-foreground">{manager.manager_name}</td>
                  <td className="text-foreground-muted">{manager.team_name}</td>
                  <td className="text-right font-mono">{metricConfig.formatValue(value)}</td>
                  <td
                    className={`text-right font-mono ${
                      delta === null
                        ? 'text-foreground-muted'
                        : delta > 0
                          ? 'text-fpl-green'
                          : delta < 0
                            ? 'text-destructive'
                            : 'text-foreground-muted'
                    }`}
                  >
                    {formatSigned(delta, metric === 'hit_roi' ? 2 : 1)}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
