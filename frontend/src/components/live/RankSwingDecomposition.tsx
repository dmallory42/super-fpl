import { type Tier, TIER_OPTIONS } from '../../lib/tiers'

export interface SwingComponents {
  captain: number
  differentials: number
  fixture: number
  fades: number
  net: number
}

interface RankSwingDecompositionProps {
  components: SwingComponents
  selectedTier: Tier
  onTierChange: (tier: Tier) => void
  showTierSelector?: boolean
}

function formatSwing(value: number): string {
  const rounded = Math.round(value * 10) / 10
  return `${rounded > 0 ? '+' : ''}${rounded.toFixed(1)}`
}

export function RankSwingDecomposition({
  components,
  selectedTier,
  onTierChange,
  showTierSelector = true,
}: RankSwingDecompositionProps) {
  const rows: Array<{ key: keyof SwingComponents; label: string; value: number }> = [
    { key: 'captain', label: 'Captain', value: components.captain },
    { key: 'differentials', label: 'Differentials', value: components.differentials },
    { key: 'fixture', label: 'Fixture Swing', value: components.fixture },
    { key: 'fades', label: 'Fades', value: components.fades },
  ]

  return (
    <div className="space-y-2.5 md:space-y-3">
      <div className="flex items-center justify-between">
        <div className="text-center">
          <div
            className={`font-mono text-xl font-bold ${
              components.net > 0
                ? 'text-fpl-green'
                : components.net < 0
                  ? 'text-destructive'
                  : 'text-foreground'
            }`}
          >
            {formatSwing(components.net)}
          </div>
          <div className="text-xs font-display uppercase tracking-wide text-foreground-dim">
            Net Rank Swing
          </div>
        </div>

        {showTierSelector && (
          <div className="flex items-center gap-1">
            <span className="text-xs text-foreground-dim mr-1">vs</span>
            {TIER_OPTIONS.map((tier) => (
              <button
                key={tier.value}
                onClick={() => onTierChange(tier.value)}
                className={`px-2 py-0.5 text-xs font-display uppercase tracking-wide rounded transition-colors ${
                  selectedTier === tier.value
                    ? 'bg-fpl-green/20 text-fpl-green'
                    : 'text-foreground-dim hover:text-foreground hover:bg-surface-elevated'
                }`}
              >
                {tier.label}
              </button>
            ))}
          </div>
        )}
      </div>

      <div className="space-y-1" key={selectedTier}>
        {rows.map((row, idx) => (
          <div
            key={row.key}
            className="p-2 rounded-lg text-sm animate-fade-in-up-fast opacity-0 bg-surface-elevated"
            style={{ animationDelay: `${idx * 20}ms` }}
          >
            <div className="flex items-center justify-between">
              <span className="text-xs text-foreground-muted font-display uppercase tracking-wide">
                {row.label}
              </span>
              <span
                className={`font-mono text-xs font-bold ${
                  row.value > 0
                    ? 'text-fpl-green'
                    : row.value < 0
                      ? 'text-destructive'
                      : 'text-foreground-muted'
                }`}
              >
                {formatSwing(row.value)}
              </span>
            </div>
          </div>
        ))}
      </div>

      <div className="p-2 rounded-lg bg-surface/50 border border-border/40 flex items-center justify-between">
        <span className="text-xs text-foreground-muted font-display uppercase tracking-wide">
          Decomposition Sum
        </span>
        <span
          className={`font-mono text-xs font-bold ${
            components.net > 0
              ? 'text-fpl-green'
              : components.net < 0
                ? 'text-destructive'
                : 'text-foreground-muted'
          }`}
        >
          {formatSwing(components.captain + components.differentials + components.fixture + components.fades)}
        </span>
      </div>
    </div>
  )
}
