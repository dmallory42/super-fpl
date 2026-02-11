import type { GameweekFixtureStatus } from '../../api/client'
import { type Tier, TIER_OPTIONS } from '../../lib/tiers'

interface FixtureImpact {
  fixtureId: number
  homeTeam: string
  awayTeam: string
  userPoints: number
  tierAvgPoints: number
  impact: number // user points - tier avg points
  isLive: boolean
  isFinished: boolean
  hasUserPlayer: boolean
}

interface FixtureThreatIndexProps {
  fixtureData: GameweekFixtureStatus | undefined
  teamsMap?: Map<number, string> // Optional, kept for interface compatibility
  fixtureImpacts: FixtureImpact[]
  selectedTier: Tier
  onTierChange: (tier: Tier) => void
  showTierSelector?: boolean
  maxRows?: number
}

export function FixtureThreatIndex({
  fixtureData,
  fixtureImpacts,
  selectedTier,
  onTierChange,
  showTierSelector = true,
  maxRows,
}: FixtureThreatIndexProps) {
  if (!fixtureData || fixtureImpacts.length === 0) {
    return (
      <div className="text-center text-foreground-muted py-4 text-sm">
        No fixture data available
      </div>
    )
  }

  // Sort by absolute impact (most impactful first)
  const sortedFixtures = [...fixtureImpacts].sort((a, b) => Math.abs(b.impact) - Math.abs(a.impact))
  const visibleFixtures = maxRows ? sortedFixtures.slice(0, maxRows) : sortedFixtures

  // Calculate totals
  const totalUserPoints = fixtureImpacts.reduce((sum, f) => sum + f.userPoints, 0)
  const totalTierAvg = fixtureImpacts.reduce((sum, f) => sum + f.tierAvgPoints, 0)
  const totalImpact = totalUserPoints - totalTierAvg

  return (
    <div className="space-y-2.5 md:space-y-3">
      {/* Header with tier selector */}
      <div className="flex items-center justify-between">
        <div className="text-center">
          <div
            className={`font-mono text-xl font-bold ${
              totalImpact > 0
                ? 'text-fpl-green'
                : totalImpact < 0
                  ? 'text-destructive'
                  : 'text-foreground'
            }`}
          >
            {totalImpact > 0 ? '+' : ''}
            {totalImpact.toFixed(1)}
          </div>
          <div className="text-[9px] font-display uppercase tracking-wide text-foreground-dim">
            Fixture Swing
          </div>
        </div>

        {showTierSelector && (
          <div className="flex items-center gap-1">
            <span className="text-[9px] md:text-[10px] text-foreground-dim mr-1">vs</span>
            {TIER_OPTIONS.map((tier) => (
              <button
                key={tier.value}
                onClick={() => onTierChange(tier.value)}
                className={`px-2 py-0.5 text-[9px] md:text-[10px] font-display uppercase tracking-wide rounded transition-colors ${
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

      {/* Summary bar */}
      <div className="flex items-center justify-between p-2 rounded-lg bg-surface-elevated text-[11px] md:text-xs">
        <div className="flex items-center gap-2">
          <span className="text-foreground-muted">Your points:</span>
          <span className="font-mono font-bold text-fpl-green">{totalUserPoints.toFixed(1)}</span>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-foreground-muted">Field points:</span>
          <span className="font-mono text-foreground-muted">{totalTierAvg.toFixed(1)}</span>
        </div>
      </div>

      {/* Fixture list */}
      <div className="space-y-1" key={selectedTier}>
        {visibleFixtures.map((fixture, idx) => {
          const isPositive = fixture.impact > 0
          const isNegative = fixture.impact < 0

          return (
            <div
              key={fixture.fixtureId}
              className={`
                p-2 rounded-lg text-sm animate-fade-in-up-fast opacity-0
                ${fixture.hasUserPlayer ? 'bg-surface-elevated' : 'bg-surface/50'}
              `}
              style={{ animationDelay: `${idx * 25}ms` }}
            >
              <div className="flex items-center justify-between">
                {/* Teams and status */}
                <div className="flex items-center gap-2 min-w-0 flex-1">
                  <span className="font-mono text-xs font-bold text-foreground">
                    {fixture.homeTeam}
                  </span>
                  <span className="text-foreground-dim text-[10px]">v</span>
                  <span className="font-mono text-xs font-bold text-foreground">
                    {fixture.awayTeam}
                  </span>

                  {fixture.isLive && (
                    <span className="w-1.5 h-1.5 rounded-full bg-destructive animate-pulse" />
                  )}
                </div>

                {/* Points comparison */}
                <div className="flex items-center gap-2 shrink-0">
                  <span
                    className={`font-mono text-xs ${fixture.hasUserPlayer ? 'text-fpl-green' : 'text-foreground-dim'}`}
                  >
                    {fixture.userPoints.toFixed(1)}
                  </span>
                  <span className="text-foreground-dim text-[10px]">vs</span>
                  <span className="font-mono text-xs text-foreground-muted">
                    {fixture.tierAvgPoints.toFixed(1)}
                  </span>

                  {/* Impact */}
                  <span
                    className={`font-mono text-xs font-bold w-12 text-right ${
                      isPositive
                        ? 'text-fpl-green'
                        : isNegative
                          ? 'text-destructive'
                          : 'text-foreground-muted'
                    }`}
                  >
                    {fixture.impact > 0 ? '+' : ''}
                    {fixture.impact.toFixed(1)}
                  </span>
                </div>
              </div>
            </div>
          )
        })}
      </div>
      {maxRows && sortedFixtures.length > maxRows && (
        <p className="text-[10px] text-foreground-dim text-center">
          Showing top {maxRows} fixtures by impact
        </p>
      )}
    </div>
  )
}
