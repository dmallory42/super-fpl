import type { RiskScore } from '../../api/client'

interface RiskMeterProps {
  managerName: string
  riskScore: RiskScore
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

export function RiskMeter({ managerName, riskScore }: RiskMeterProps) {
  const percentage = Math.min(100, riskScore.score)

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
