interface PlayerVariance {
  playerId: number
  name: string
  predicted: number
  actual: number
}

interface VarianceAnalysisProps {
  players: PlayerVariance[]
  totalPredicted: number
  totalActual: number
}

export function VarianceAnalysis({ players, totalPredicted, totalActual }: VarianceAnalysisProps) {
  const totalVariance = totalActual - totalPredicted
  const isLucky = totalVariance > 0
  const isUnlucky = totalVariance < 0
  const isNeutral = totalVariance === 0

  // Sort players by variance (biggest overperformers first)
  const sortedPlayers = [...players].sort((a, b) => {
    const aVariance = a.actual - a.predicted
    const bVariance = b.actual - b.predicted
    return bVariance - aVariance
  })

  // Variance label
  const varianceLabel = isNeutral ? 'As Expected' : isLucky ? 'Positive' : 'Negative'

  return (
    <div className="space-y-4">
      {/* Summary */}
      <div className="flex items-center justify-between">
        {/* Expected */}
        <div className="text-center">
          <div className="font-mono text-2xl font-bold text-foreground-muted">
            {totalPredicted.toFixed(1)}
          </div>
          <div className="text-[10px] font-display uppercase tracking-wider text-foreground-dim">
            Expected
          </div>
        </div>

        {/* Variance indicator */}
        <div className="text-center px-4">
          <div
            className={`font-mono text-xl font-bold ${
              isLucky ? 'text-fpl-green' : isUnlucky ? 'text-destructive' : 'text-foreground-muted'
            }`}
          >
            {totalVariance > 0 ? '+' : ''}
            {totalVariance.toFixed(1)}
          </div>
          <div
            className={`text-[10px] font-display uppercase tracking-wider ${
              isLucky ? 'text-fpl-green' : isUnlucky ? 'text-destructive' : 'text-foreground-dim'
            }`}
          >
            {varianceLabel}
          </div>
        </div>

        {/* Actual */}
        <div className="text-center">
          <div
            className={`font-mono text-2xl font-bold ${
              isLucky ? 'text-fpl-green' : isUnlucky ? 'text-destructive' : 'text-foreground'
            }`}
          >
            {totalActual}
          </div>
          <div className="text-[10px] font-display uppercase tracking-wider text-foreground-dim">
            Actual
          </div>
        </div>
      </div>

      {/* Variance meter bar */}
      <div className="relative h-2 bg-surface-elevated rounded-full overflow-hidden">
        <div
          className={`absolute inset-y-0 transition-all duration-500 ${
            isLucky
              ? 'bg-fpl-green right-1/2'
              : isUnlucky
                ? 'bg-destructive left-1/2'
                : 'bg-foreground-dim'
          }`}
          style={{
            width: isNeutral ? '4px' : `${Math.min(Math.abs(totalVariance) * 2, 50)}%`,
            left: isNeutral ? '50%' : undefined,
            transform: isNeutral ? 'translateX(-50%)' : undefined,
          }}
        />
        {/* Center marker */}
        <div className="absolute top-0 bottom-0 left-1/2 w-0.5 bg-foreground-dim/50 -translate-x-1/2" />
      </div>

      {/* Player breakdown */}
      {sortedPlayers.length > 0 && (
        <div className="pt-3 border-t border-border/50">
          <div className="text-[10px] text-foreground-dim font-display uppercase tracking-wider mb-2">
            Player Breakdown
          </div>
          <div className="space-y-1.5">
            {sortedPlayers.map((player, idx) => {
              const variance = Math.round((player.actual - player.predicted) * 10) / 10
              const isPositive = variance > 0
              const isNegative = variance < 0

              return (
                <div
                  key={player.playerId}
                  className="flex items-center justify-between text-sm animate-fade-in-up-fast opacity-0"
                  style={{ animationDelay: `${idx * 30}ms` }}
                >
                  <span className="text-foreground truncate flex-1">{player.name}</span>
                  <div className="flex items-center gap-3 shrink-0">
                    <span className="font-mono text-foreground-muted w-8 text-right">
                      {player.predicted.toFixed(1)}
                    </span>
                    <span className="text-foreground-dim">→</span>
                    <span className="font-mono text-foreground w-6 text-right">
                      {player.actual}
                    </span>
                    <span
                      className={`font-mono font-bold w-10 text-right ${
                        isPositive
                          ? 'text-fpl-green'
                          : isNegative
                            ? 'text-destructive'
                            : 'text-foreground-muted'
                      }`}
                    >
                      {variance > 0 ? '+' : ''}
                      {variance.toFixed(1)}
                    </span>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
