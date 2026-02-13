import { useMemo } from 'react'
import type { ManagerSeasonAnalysisResponse } from '../../api/client'

interface TransferQualityScorecardProps {
  seasonAnalysis: ManagerSeasonAnalysisResponse
}

function formatSigned(value: number, precision = 1): string {
  const prefix = value > 0 ? '+' : ''
  return `${prefix}${value.toFixed(precision)}`
}

export function TransferQualityScorecard({ seasonAnalysis }: TransferQualityScorecardProps) {
  const rows = useMemo(
    () => [...seasonAnalysis.transfer_analytics].sort((a, b) => a.gameweek - b.gameweek),
    [seasonAnalysis.transfer_analytics]
  )

  const totals = useMemo(() => {
    return rows.reduce(
      (acc, row) => {
        acc.transferCount += row.transfer_count
        acc.transferCost += row.transfer_cost
        acc.foresightGain += row.foresight_gain
        acc.hindsightGain += row.hindsight_gain
        acc.netGain += row.net_gain
        return acc
      },
      {
        transferCount: 0,
        transferCost: 0,
        foresightGain: 0,
        hindsightGain: 0,
        netGain: 0,
      }
    )
  }, [rows])

  if (rows.length === 0) {
    return (
      <div className="rounded-lg border border-border p-4 text-sm text-foreground-muted">
        No transfer weeks recorded yet.
      </div>
    )
  }

  const transferRoi = totals.transferCost > 0 ? totals.netGain / totals.transferCost : null

  return (
    <div className="space-y-4" data-testid="transfer-quality-scorecard">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div className="rounded-lg border border-border p-3">
          <div className="text-xs uppercase text-foreground-muted">Transfer Weeks</div>
          <div className="font-mono text-xl text-foreground">{rows.length}</div>
        </div>
        <div className="rounded-lg border border-border p-3">
          <div className="text-xs uppercase text-foreground-muted">Transfers</div>
          <div className="font-mono text-xl text-foreground">{totals.transferCount}</div>
        </div>
        <div className="rounded-lg border border-fpl-green/30 bg-fpl-green/5 p-3">
          <div className="text-xs uppercase text-foreground-muted">Foresight Gain</div>
          <div className="font-mono text-xl text-foreground">{formatSigned(totals.foresightGain)}</div>
        </div>
        <div className="rounded-lg border border-highlight/30 bg-highlight/5 p-3">
          <div className="text-xs uppercase text-foreground-muted">Hindsight Gain</div>
          <div className="font-mono text-xl text-foreground">{formatSigned(totals.hindsightGain)}</div>
        </div>
        <div className="rounded-lg border border-border p-3">
          <div className="text-xs uppercase text-foreground-muted">Net ROI</div>
          <div className="font-mono text-xl text-foreground">
            {transferRoi === null ? '—' : transferRoi.toFixed(2)}
          </div>
        </div>
      </div>

      <div className="overflow-x-auto -mx-4">
        <table className="table-broadcast min-w-[840px]" data-testid="transfer-quality-table">
          <thead>
            <tr>
              <th>GW</th>
              <th className="text-right">Transfers</th>
              <th className="text-right">Cost</th>
              <th className="text-right">Expected Gain</th>
              <th className="text-right">Realized Gain</th>
              <th className="text-right">Net ROI</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const rowRoi = row.transfer_cost > 0 ? row.net_gain / row.transfer_cost : null
              return (
                <tr key={row.gameweek}>
                  <td className="font-mono text-foreground-muted">{row.gameweek}</td>
                  <td className="text-right font-mono">{row.transfer_count}</td>
                  <td className="text-right font-mono">{row.transfer_cost}</td>
                  <td className="text-right font-mono">{formatSigned(row.foresight_gain)}</td>
                  <td className="text-right font-mono">{formatSigned(row.hindsight_gain)}</td>
                  <td className="text-right font-mono">{rowRoi === null ? '—' : rowRoi.toFixed(2)}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
