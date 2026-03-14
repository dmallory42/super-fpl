import type { RiskScore } from '../../api/client'

interface RiskMeterProps {
  managerName?: string
  riskScore: RiskScore
  compact?: boolean
}

const levelColors = {
  low: 'bg-tt-green',
  medium: 'bg-tt-yellow',
  high: 'bg-destructive',
}

const levelBgColors = {
  low: 'bg-tt-green/20',
  medium: 'bg-yellow-500/20',
  high: 'bg-destructive/20',
}

const levelTextColors = {
  low: 'text-tt-green',
  medium: 'text-tt-yellow',
  high: 'text-destructive',
}

export function RiskMeter({ managerName, riskScore, compact = false }: RiskMeterProps) {
  const percentage = Math.min(100, riskScore.score)

  if (compact) {
    return (
      <div className="flex items-center gap-2">
        <div className="w-16 h-2 bg-surface-elevated overflow-hidden">
          <div
            className={`h-full ${levelColors[riskScore.level]}`}
            style={{ width: `${percentage}%` }}
          />
        </div>
        <span className={`text-xs uppercase tracking-wider ${levelTextColors[riskScore.level]}`}>
          {riskScore.level}
        </span>
      </div>
    )
  }

  return (
    <div className={`p-4 ${levelBgColors[riskScore.level]} border border-border`}>
      <div className="flex items-center justify-between mb-3">
        <span className="text-foreground font-medium truncate max-w-[150px]">{managerName}</span>
        <span
          className={`text-xs uppercase tracking-wider font-bold ${levelTextColors[riskScore.level]}`}
        >
          {riskScore.level} risk
        </span>
      </div>

      {/* Risk bar */}
      <div className="h-3 bg-surface overflow-hidden mb-2">
        <div
          className={`h-full ${levelColors[riskScore.level]}`}
          style={{ width: `${percentage}%` }}
        />
      </div>

      <div className="flex justify-between text-xs text-foreground-muted">
        <span>Score: {riskScore.score.toFixed(1)}</span>
        <span>Captain risk: {riskScore.breakdown.captain_risk.toFixed(0)}</span>
      </div>
    </div>
  )
}
