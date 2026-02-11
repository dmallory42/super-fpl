import { formatRank } from '../../lib/format'

interface RankProjectionProps {
  currentRank: number
  previousRank: number
  currentPoints: number
  tierAvgPoints: number
  fixturesFinished: number
  fixturesTotal: number
  compact?: boolean
}

export function RankProjection({
  currentRank,
  previousRank,
  currentPoints,
  tierAvgPoints,
  fixturesFinished,
  fixturesTotal,
  compact = false,
}: RankProjectionProps) {
  // Calculate progress
  const progressPercent =
    fixturesTotal > 0 ? Math.round((fixturesFinished / fixturesTotal) * 100) : 0

  // Calculate rank movement
  const rankMovement = previousRank - currentRank // positive = improved (lower rank is better)
  const isGaining = rankMovement > 0
  const isLosing = rankMovement < 0
  const isSteady = rankMovement === 0

  // Calculate pace difference vs tier
  const paceDifference = currentPoints - tierAvgPoints

  // Calculate velocity label
  const velocityLabel = isSteady
    ? 'Steady'
    : isGaining
      ? `Gaining ~${formatRank(Math.abs(rankMovement))} places`
      : `Losing ~${formatRank(Math.abs(rankMovement))} places`

  return (
    <div className={compact ? 'space-y-3' : 'space-y-4'}>
      {/* Main rank display */}
      <div className="flex items-center justify-between">
        {/* Current rank with movement */}
        <div className="flex items-center gap-3">
          <div className="text-center">
            <div
              className={`font-mono ${compact ? 'text-2xl' : 'text-3xl'} font-bold ${
                isGaining ? 'text-fpl-green' : isLosing ? 'text-destructive' : 'text-foreground'
              }`}
            >
              {formatRank(currentRank)}
            </div>
            <div className="text-xs font-display uppercase tracking-wide text-foreground-dim">
              Current Rank
            </div>
          </div>

          {/* Movement indicator */}
          {!isSteady && (
            <div
              className={`flex items-center gap-1 ${compact ? 'text-xs' : 'text-sm'} ${
                isGaining ? 'text-fpl-green' : 'text-destructive'
              }`}
            >
              <span>{isGaining ? '↑' : '↓'}</span>
              <span className="font-mono font-bold">{formatRank(Math.abs(rankMovement))}</span>
            </div>
          )}
        </div>

        {/* Progress ring */}
        <div className={`relative ${compact ? 'w-14 h-14' : 'w-16 h-16'}`}>
          <svg className="w-full h-full -rotate-90" viewBox="0 0 36 36">
            {/* Background ring */}
            <circle
              cx="18"
              cy="18"
              r="15.5"
              fill="none"
              stroke="currentColor"
              strokeWidth="3"
              className="text-surface-elevated"
            />
            {/* Progress arc */}
            <circle
              cx="18"
              cy="18"
              r="15.5"
              fill="none"
              stroke="currentColor"
              strokeWidth="3"
              strokeLinecap="round"
              strokeDasharray={`${progressPercent} 100`}
              className="text-fpl-green transition-all duration-500"
            />
          </svg>
          {/* Center text */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span
              className={`font-mono font-bold text-fpl-green ${compact ? 'text-xs' : 'text-sm'}`}
            >
              {progressPercent}%
            </span>
            <span className="text-[9px] text-foreground-dim font-display uppercase tracking-wide">
              GW Complete
            </span>
          </div>
        </div>
      </div>

      {/* Pace comparison */}
      <div className="flex items-center justify-between p-2 rounded-lg bg-surface-elevated">
        <span className="text-xs font-display uppercase tracking-wide text-foreground-muted">
          vs Tier Avg
        </span>
        <span
          className={`font-mono font-bold ${
            paceDifference > 0
              ? 'text-fpl-green'
              : paceDifference < 0
                ? 'text-destructive'
                : 'text-foreground-muted'
          }`}
        >
          {paceDifference > 0 ? '+' : ''}
          {paceDifference.toFixed(1)}
        </span>
      </div>

      {/* Velocity indicator */}
      <div className={`text-center text-foreground-muted ${compact ? 'text-xs' : 'text-sm'}`}>
        {velocityLabel}
      </div>
    </div>
  )
}
