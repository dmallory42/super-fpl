import type { RiskScore } from '../../api/client'

interface RiskMeterProps {
  managerName?: string
  riskScore: RiskScore
  compact?: boolean
}

const levelColors = {
  low: 'from-fpl-green to-green-600',
  medium: 'from-yellow-500 to-yellow-600',
  high: 'from-destructive to-red-600',
}

const levelBgColors = {
  low: 'bg-fpl-green/20',
  medium: 'bg-yellow-500/20',
  high: 'bg-destructive/20',
}

const levelTextColors = {
  low: 'text-fpl-green',
  medium: 'text-yellow-400',
  high: 'text-destructive',
}

export function RiskMeter({ managerName, riskScore, compact = false }: RiskMeterProps) {
  const percentage = Math.min(100, riskScore.score)

  if (compact) {
    return (
      <div className="flex items-center gap-2">
        <div className="w-16 h-2 bg-surface-elevated rounded-full overflow-hidden">
          <div
            className={`h-full bg-gradient-to-r ${levelColors[riskScore.level]} transition-all duration-500`}
            style={{ width: `${percentage}%` }}
          />
        </div>
        <span
          className={`text-xs font-display uppercase tracking-wider ${levelTextColors[riskScore.level]}`}
        >
          {riskScore.level}
        </span>
      </div>
    )
  }

  return (
    <div
      className={`p-4 rounded-lg ${levelBgColors[riskScore.level]} border border-border animate-fade-in-up`}
    >
      <div className="flex items-center justify-between mb-3">
        <span className="text-foreground font-medium truncate max-w-[150px]">{managerName}</span>
        <span
          className={`text-xs font-display uppercase tracking-wider font-bold ${levelTextColors[riskScore.level]}`}
        >
          {riskScore.level} risk
        </span>
      </div>

      {/* Risk bar */}
      <div className="h-3 bg-surface rounded-full overflow-hidden mb-2">
        <div
          className={`h-full bg-gradient-to-r ${levelColors[riskScore.level]} transition-all duration-700`}
          style={{ width: `${percentage}%` }}
        />
      </div>

      <div className="flex justify-between text-xs text-foreground-muted font-mono">
        <span>Score: {riskScore.score.toFixed(1)}</span>
        <span>Captain risk: {riskScore.breakdown.captain_risk.toFixed(0)}</span>
      </div>
    </div>
  )
}
