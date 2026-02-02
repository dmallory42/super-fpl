import type { TierComparison } from '../../hooks/useLiveSamples'

interface ComparisonBarsProps {
  userPoints: number
  comparisons: TierComparison[]
  animationDelay?: number
}

export function ComparisonBars({ userPoints, comparisons, animationDelay = 0 }: ComparisonBarsProps) {
  if (comparisons.length === 0) {
    return (
      <div className="text-center text-foreground-muted py-4">
        No sample data available yet
      </div>
    )
  }

  // Find max points for scaling bars
  const allPoints = [...comparisons.map(c => c.avgPoints), userPoints]
  const maxPoints = Math.max(...allPoints)

  return (
    <div className="space-y-3">
      {/* User's points bar */}
      <div
        className="animate-fade-in-up opacity-0"
        style={{ animationDelay: `${animationDelay}ms` }}
      >
        <div className="flex items-center justify-between mb-1">
          <span className="font-display text-xs uppercase tracking-wider text-fpl-green">You</span>
          <span className="font-mono font-bold text-fpl-green">{userPoints} pts</span>
        </div>
        <div className="h-3 bg-surface rounded-full overflow-hidden">
          <div
            className="h-full bg-gradient-to-r from-fpl-green to-emerald-500 rounded-full transition-all duration-500"
            style={{ width: `${(userPoints / maxPoints) * 100}%` }}
          />
        </div>
      </div>

      {/* Tier comparison bars */}
      {comparisons.map((comp, idx) => {
        const isAhead = comp.difference > 0
        const isBehind = comp.difference < 0
        const diffText = isAhead ? `+${comp.difference.toFixed(0)}` : comp.difference.toFixed(0)

        return (
          <div
            key={comp.tier}
            className="animate-fade-in-up opacity-0"
            style={{ animationDelay: `${animationDelay + (idx + 1) * 50}ms` }}
          >
            <div className="flex items-center justify-between mb-1">
              <span className="font-display text-xs uppercase tracking-wider text-foreground-muted">
                {comp.tierLabel}
              </span>
              <div className="flex items-center gap-2">
                <span className="font-mono text-foreground-muted">{comp.avgPoints.toFixed(0)} pts</span>
                <span
                  className={`font-mono text-xs font-bold ${
                    isAhead ? 'text-fpl-green' : isBehind ? 'text-destructive' : 'text-foreground-dim'
                  }`}
                >
                  ({diffText})
                </span>
              </div>
            </div>
            <div className="h-2 bg-surface rounded-full overflow-hidden">
              <div
                className="h-full bg-foreground-dim/30 rounded-full transition-all duration-500"
                style={{ width: `${(comp.avgPoints / maxPoints) * 100}%` }}
              />
            </div>
          </div>
        )
      })}

      {/* Sample size note */}
      <p className="text-xs text-foreground-dim text-center mt-2">
        Based on {comparisons[0]?.sampleSize.toLocaleString() ?? 0} sampled managers per tier
      </p>
    </div>
  )
}

interface RankMovementProps {
  currentRank: number
  startingRank?: number
}

export function RankMovement({ currentRank, startingRank }: RankMovementProps) {
  if (!startingRank) {
    return (
      <div className="text-center">
        <div className="font-mono text-2xl font-bold text-foreground">
          ~{formatRank(currentRank)}
        </div>
        <div className="text-xs text-foreground-dim">Est. Rank</div>
      </div>
    )
  }

  const movement = startingRank - currentRank
  const isUp = movement > 0
  const isDown = movement < 0

  return (
    <div className="text-center">
      <div className={`font-mono text-2xl font-bold flex items-center justify-center gap-1 ${
        isUp ? 'text-fpl-green' : isDown ? 'text-destructive' : 'text-foreground'
      }`}>
        {isUp && '↑'}
        {isDown && '↓'}
        {formatRank(currentRank)}
      </div>
      <div className="text-xs text-foreground-dim">
        {isUp ? `Up from ${formatRank(startingRank)}` : isDown ? `Down from ${formatRank(startingRank)}` : 'Est. Rank'}
      </div>
    </div>
  )
}

function formatRank(rank: number): string {
  if (rank >= 1000000) return `${(rank / 1000000).toFixed(1)}M`
  if (rank >= 1000) return `${Math.round(rank / 1000)}K`
  return rank.toLocaleString()
}
