interface DifferentialPlayer {
  playerId: number
  name: string
  points: number
  eo: number // Effective ownership 0-100+
  impact: number // Positive = helping rank, negative = hurting
  multiplier: number // 1 = normal, 2 = captain, 3 = TC
}

interface DifferentialAnalysisProps {
  players: DifferentialPlayer[]
  tierLabel: string
  showTierLabel?: boolean
}

// EO thresholds for categorization
const DIFFERENTIAL_THRESHOLD = 20 // Below this = differential
const TEMPLATE_THRESHOLD = 50 // Above this = template

type Category = 'differential' | 'template' | 'moderate'

function categorizePlayer(eo: number): Category {
  if (eo < DIFFERENTIAL_THRESHOLD) return 'differential'
  if (eo >= TEMPLATE_THRESHOLD) return 'template'
  return 'moderate'
}

const categoryConfig: Record<Category, { label: string; color: string }> = {
  differential: { label: 'Differential', color: 'text-tt-magenta' },
  template: { label: 'Template', color: 'text-foreground-muted' },
  moderate: { label: 'Moderate', color: 'text-foreground' },
}

export function DifferentialAnalysis({
  players,
  tierLabel,
  showTierLabel = true,
}: DifferentialAnalysisProps) {
  if (players.length === 0) {
    return (
      <div className="text-center text-foreground-muted py-4 text-sm">
        No differential data available
      </div>
    )
  }

  // Sort by impact descending
  const sortedPlayers = [...players].sort((a, b) => b.impact - a.impact)

  return (
    <div className="space-y-3">
      {/* Header */}
      <div className="flex items-center justify-between">
        {showTierLabel ? (
          <span className="text-sm uppercase text-foreground-muted">vs {tierLabel}</span>
        ) : (
          <span />
        )}
      </div>

      {/* Player list */}
      <div className="space-y-1.5">
        {sortedPlayers.map((player) => {
          const category = categorizePlayer(player.eo)
          const config = categoryConfig[category]
          const roi = player.points > 0 ? player.impact / player.points : 0
          const isCaptain = player.multiplier >= 2
          const isPositiveImpact = player.impact > 0

          return (
            <div
              key={player.playerId}
              className={`
                p-2 text-sm
                ${category === 'differential' ? 'bg-tt-magenta/10 ring-1 ring-tt-magenta/30' : 'bg-surface-elevated'}
              `}
            >
              {/* Row 1: Name and category */}
              <div className="flex items-center justify-between mb-1">
                <div className="flex items-center gap-1.5 min-w-0">
                  {isCaptain && <span className="text-tt-yellow text-sm">©</span>}
                  <span className="text-foreground font-medium truncate">{player.name}</span>
                </div>
                <span className={`text-sm uppercase shrink-0 ${config.color}`}>{config.label}</span>
              </div>

              {/* Row 2: Metrics */}
              <div className="flex items-center justify-between text-sm">
                <div className="flex items-center gap-2">
                  <span className="text-foreground-muted">{player.points} pts</span>
                  <span
                    className={`${category === 'differential' ? 'text-tt-magenta' : 'text-foreground-dim'}`}
                  >
                    {player.eo.toFixed(0)}% EO
                  </span>
                </div>
                <div className="flex items-center gap-3">
                  <span
                    className={`font-bold ${
                      isPositiveImpact ? 'text-tt-green' : 'text-destructive'
                    }`}
                  >
                    {player.impact > 0 ? '+' : ''}
                    {player.impact.toFixed(1)}
                  </span>
                  <span
                    className={`${
                      roi > 0.5
                        ? 'text-tt-green'
                        : roi < -0.5
                          ? 'text-destructive'
                          : 'text-foreground-dim'
                    }`}
                  >
                    ROI {roi > 0 ? '+' : ''}
                    {roi.toFixed(2)}
                  </span>
                </div>
              </div>
            </div>
          )
        })}
      </div>

      {/* Legend */}
      <div className="pt-2 border-t border-border/50">
        <div className="flex items-center justify-center gap-4 text-sm uppercase">
          <div className="flex items-center gap-1">
            <div className="w-2 h-2 bg-tt-magenta" />
            <span className="text-foreground-dim">
              Differential (&lt;{DIFFERENTIAL_THRESHOLD}%)
            </span>
          </div>
          <div className="flex items-center gap-1">
            <div className="w-2 h-2 bg-foreground/30" />
            <span className="text-foreground-dim">Template (&gt;{TEMPLATE_THRESHOLD}%)</span>
          </div>
        </div>
      </div>
    </div>
  )
}
