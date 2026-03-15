import { formatRank } from '../../lib/format'

interface RankProjectionProps {
  currentRank: number
  previousRank: number
  fixturesFinished: number
  fixturesTotal: number
  compact?: boolean
}

export function RankProjection({
  currentRank,
  previousRank,
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

  return (
    <div className={compact ? 'space-y-3' : 'space-y-4'}>
      {/* Main rank display */}
      <div className="flex items-center justify-between">
        {/* Current rank with movement */}
        <div className="flex items-center gap-3">
          <div className="text-center">
            <div
              className={`${compact ? 'text-2xl' : 'text-3xl'} font-bold ${
                isGaining ? 'text-tt-green' : isLosing ? 'text-destructive' : 'text-foreground'
              }`}
            >
              {formatRank(currentRank)}
            </div>
            <div className="text-sm uppercase text-foreground-dim">Current Rank</div>
          </div>

          {/* Movement indicator */}
          {!isSteady && (
            <div
              className={`flex items-center gap-1 ${compact ? 'text-sm' : 'text-base'} ${
                isGaining ? 'text-tt-green' : 'text-destructive'
              }`}
            >
              <span>{isGaining ? '↑' : '↓'}</span>
              <span className="font-bold">{formatRank(Math.abs(rankMovement))}</span>
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
              className="text-tt-green"
            />
          </svg>
          {/* Center text */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className={`font-bold text-tt-green ${compact ? 'text-sm' : 'text-base'}`}>
              {fixturesFinished}/{fixturesTotal}
            </span>
            <span className="text-sm text-foreground-dim uppercase">Matches</span>
          </div>
        </div>
      </div>
    </div>
  )
}
