import { useMemo } from 'react'
import type { ManagerSeasonAnalysisResponse } from '../../api/client'

interface TransferQualityScorecardProps {
  seasonAnalysis: ManagerSeasonAnalysisResponse
}

function formatSigned(value: number, precision = 1): string {
  const prefix = value > 0 ? '+' : ''
  return `${prefix}${value.toFixed(precision)}`
}

function signedValueClass(value: number): string {
  if (value > 0) return 'text-tt-green'
  if (value < 0) return 'text-destructive'
  return 'text-foreground'
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
        if (row.foresight_gain !== null) {
          acc.foresightGain += row.foresight_gain
          acc.foresightWeeks += 1
        }
        acc.hindsightGain += row.hindsight_gain
        return acc
      },
      {
        transferCount: 0,
        transferCost: 0,
        foresightGain: 0,
        foresightWeeks: 0,
        hindsightGain: 0,
      }
    )
  }, [rows])

  if (rows.length === 0) {
    return (
      <div className=" border border-border p-4 text-base text-foreground-muted">
        No transfer weeks recorded yet.
      </div>
    )
  }

  return (
    <div className="space-y-4" data-testid="transfer-quality-scorecard">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="stat-panel" data-testid="transfer-quality-weeks">
          <div className="bg-tt-cyan text-tt-black px-2 py-0.5 text-sm uppercase font-bold inline-block">
            Transfer Weeks
          </div>
          <div className="text-xl text-foreground mt-2">{rows.length}</div>
        </div>
        <div className="stat-panel" data-testid="transfer-quality-count">
          <div className="bg-tt-cyan text-tt-black px-2 py-0.5 text-sm uppercase font-bold inline-block">
            Transfers
          </div>
          <div className="text-xl text-foreground mt-2">{totals.transferCount}</div>
        </div>
        <div className="stat-panel" data-testid="transfer-quality-expected">
          <div className="bg-tt-cyan text-tt-black px-2 py-0.5 text-sm uppercase font-bold inline-block">
            Expected Gain (Snapshot)
          </div>
          <div
            className={`text-xl mt-2 ${
              totals.foresightWeeks > 0 ? signedValueClass(totals.foresightGain) : 'text-foreground'
            }`}
          >
            {totals.foresightWeeks > 0 ? formatSigned(totals.foresightGain) : '—'}
          </div>
          <div className="text-sm text-foreground-dim mt-1">
            {totals.foresightWeeks}/{rows.length} GWs covered
          </div>
        </div>
        <div className="stat-panel" data-testid="transfer-quality-realized">
          <div className="bg-tt-cyan text-tt-black px-2 py-0.5 text-sm uppercase font-bold inline-block">
            Realized Gain
          </div>
          <div className={`text-xl mt-2 ${signedValueClass(totals.hindsightGain)}`}>
            {formatSigned(totals.hindsightGain)}
          </div>
        </div>
      </div>

      <div className="overflow-x-auto -mx-4">
        <table className="table-broadcast min-w-[980px]" data-testid="transfer-quality-table">
          <thead>
            <tr>
              <th>GW</th>
              <th>Moves</th>
              <th className="text-right">Transfers</th>
              <th className="text-right">Cost</th>
              <th className="text-right">Expected Gain</th>
              <th className="text-right">Realized Gain</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              return (
                <tr key={row.gameweek}>
                  <td className="text-foreground-muted">{row.gameweek}</td>
                  <td className="py-2">
                    {row.transfers && row.transfers.length > 0 ? (
                      <div className="space-y-1">
                        {row.transfers.map((move, idx) => (
                          <div key={`${move.out_id}-${move.in_id}-${idx}`} className="text-sm">
                            <span className="text-destructive">{move.out_name}</span>
                            <span className="text-foreground-dim"> → </span>
                            <span className="text-tt-green">{move.in_name}</span>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <span className="text-foreground-dim">—</span>
                    )}
                  </td>
                  <td className="text-right">{row.transfer_count}</td>
                  <td className="text-right">{row.transfer_cost}</td>
                  <td
                    className={`text-right ${
                      row.foresight_gain === null
                        ? 'text-foreground-dim'
                        : signedValueClass(row.foresight_gain)
                    }`}
                  >
                    {row.foresight_gain === null ? '—' : formatSigned(row.foresight_gain)}
                  </td>
                  <td className={`text-right ${signedValueClass(row.hindsight_gain)}`}>
                    {formatSigned(row.hindsight_gain)}
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
