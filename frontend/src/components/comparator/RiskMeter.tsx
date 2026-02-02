import type { RiskScore } from '../../api/client'

interface RiskMeterProps {
  managerName?: string
  riskScore: RiskScore
  compact?: boolean
}

const levelColors = {
  low: 'bg-green-500',
  medium: 'bg-yellow-500',
  high: 'bg-red-500',
}

const levelTextColors = {
  low: 'text-green-400',
  medium: 'text-yellow-400',
  high: 'text-red-400',
}

export function RiskMeter({ managerName, riskScore, compact = false }: RiskMeterProps) {
  const percentage = Math.min(100, riskScore.score)

  if (compact) {
    return (
      <div className="flex items-center gap-2">
        <div className="w-16 h-2 bg-gray-700 rounded-full overflow-hidden">
          <div
            className={`h-full ${levelColors[riskScore.level]}`}
            style={{ width: `${percentage}%` }}
          />
        </div>
        <span className={`text-xs font-medium ${levelTextColors[riskScore.level]}`}>
          {riskScore.level}
        </span>
      </div>
    )
  }

  return (
    <div className="p-4 bg-gray-800 rounded-lg">
      <div className="flex items-center justify-between mb-2">
        <span className="text-white font-medium truncate max-w-[150px]">{managerName}</span>
        <span className={`text-sm font-bold uppercase ${levelTextColors[riskScore.level]}`}>
          {riskScore.level} risk
        </span>
      </div>

      {/* Risk bar */}
      <div className="h-3 bg-gray-700 rounded-full overflow-hidden mb-2">
        <div
          className={`h-full ${levelColors[riskScore.level]} transition-all duration-500`}
          style={{ width: `${percentage}%` }}
        />
      </div>

      <div className="flex justify-between text-xs text-gray-400">
        <span>Score: {riskScore.score.toFixed(1)}</span>
        <span>Captain risk: {riskScore.breakdown.captain_risk.toFixed(0)}</span>
      </div>
    </div>
  )
}
