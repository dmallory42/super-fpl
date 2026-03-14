import type { TierComparison } from '../../hooks/useLiveSamples'
import { formatRank } from '../../lib/format'
import { type Tier, TIER_OPTIONS, TIER_CONFIG } from '../../lib/tiers'

export interface PlayerImpact {
  playerId: number
  name: string
  points: number
  eo: number // Effective ownership (0-100)
  relativeEO: number // Your exposure minus tier EO (positive = you have more, negative = you have less)
  impact: number // Positive = helped rank, negative = hurt rank
  owned: boolean // Whether user owns this player
}

interface ComparisonBarsProps {
  userPoints: number
  comparisons: TierComparison[]
  playerImpacts?: PlayerImpact[]
  selectedTier: Tier
  onTierChange: (tier: Tier) => void
  showTierSelector?: boolean
}

export function ComparisonBars({
  userPoints,
  comparisons,
  playerImpacts,
  selectedTier,
  onTierChange,
  showTierSelector = true,
}: ComparisonBarsProps) {
  if (comparisons.length === 0) {
    return null
  }

  // Calculate the range for the gauge based on actual points
  const allPoints = [userPoints, ...comparisons.map((c) => c.avgPoints)]
  const minPoints = Math.min(...allPoints)
  const maxPoints = Math.max(...allPoints)
  const range = maxPoints - minPoints || 10 // Avoid division by zero
  const padding = range * 0.15 // 15% padding on each side
  const scaleMin = Math.floor(minPoints - padding)
  const scaleMax = Math.ceil(maxPoints + padding)
  const scaleRange = scaleMax - scaleMin

  // Convert points to percentage position on the track
  const pointsToPercent = (pts: number) => {
    return ((pts - scaleMin) / scaleRange) * 100
  }

  // Generate scale tick values (round to nice numbers)
  const tickCount = 5
  const tickStep = Math.ceil(scaleRange / tickCount / 5) * 5 // Round to nearest 5
  const ticks: number[] = []
  const firstTick = Math.ceil(scaleMin / tickStep) * tickStep
  for (let t = firstTick; t <= scaleMax; t += tickStep) {
    ticks.push(t)
  }

  type MarkerLayout = {
    position: number
    lane: number
    markerOffsetPx: number
  }

  const markerLayoutsByTier = new Map<string, MarkerLayout>()
  const overlapThresholdPercent = 4
  const markerSpreadPx = 22

  const sortedPoints = comparisons
    .map((comp) => ({
      tier: comp.tier,
      position: pointsToPercent(comp.avgPoints),
    }))
    .sort((a, b) => a.position - b.position)

  let clusterStart = 0
  while (clusterStart < sortedPoints.length) {
    const cluster = [sortedPoints[clusterStart]]
    let cursor = clusterStart + 1

    while (cursor < sortedPoints.length) {
      const prev = sortedPoints[cursor - 1]
      const current = sortedPoints[cursor]
      if (current.position - prev.position >= overlapThresholdPercent) {
        break
      }
      cluster.push(current)
      cursor++
    }

    cluster.forEach((entry, idx) => {
      const lane = cluster.length > 1 ? idx : 0
      const markerOffsetPx =
        cluster.length > 1 ? (idx - (cluster.length - 1) / 2) * markerSpreadPx : 0

      markerLayoutsByTier.set(entry.tier, {
        position: entry.position,
        lane,
        markerOffsetPx,
      })
    })

    clusterStart = cursor
  }

  const trackPaddingBottomPx = 26

  return (
    <div className="space-y-3 md:space-y-4">
      {/* Race Track */}
      <div
        className="relative pt-10 md:pt-12"
        style={{ paddingBottom: `${trackPaddingBottomPx}px` }}
      >
        {/* The track line */}
        <div className="absolute left-0 right-0 top-1/2 h-1 bg-foreground-dim/30" />

        {/* Track segments/notches */}
        <div className="absolute left-0 right-0 top-1/2 -translate-y-1/2 flex justify-between px-2">
          {ticks.map((tick) => {
            const pos = pointsToPercent(tick)
            if (pos < 0 || pos > 100) return null
            return (
              <div
                key={tick}
                className="absolute w-px h-2 bg-foreground-dim/40"
                style={{ left: `${pos}%` }}
              />
            )
          })}
        </div>

        {/* Tier markers on the track */}
        {comparisons.map((comp) => {
          const config = TIER_CONFIG[comp.tier as Tier] || {
            abbrev: comp.tier,
            color: 'bg-slate-500',
          }
          const layout = markerLayoutsByTier.get(comp.tier)
          const position = layout?.position ?? pointsToPercent(comp.avgPoints)

          return (
            <div
              key={comp.tier}
              className="group absolute top-1/2 -translate-y-1/2 -translate-x-1/2"
              data-testid={`tier-marker-${comp.tier}`}
              title={`${comp.tierLabel}: ${comp.avgPoints.toFixed(0)} pts`}
              aria-label={`${comp.tierLabel} average ${comp.avgPoints.toFixed(0)} points`}
              style={{
                left: `${position}%`,
                marginLeft: `${layout?.markerOffsetPx ?? 0}px`,
              }}
            >
              {/* Marker */}
              <div className={`w-3 h-3 ${config.color} ring-2 ring-background`} />
              <div
                className="pointer-events-none absolute bottom-full mb-2 left-1/2 -translate-x-1/2 whitespace-nowrap bg-background/90 px-1.5 py-0.5 text-[10px] text-foreground opacity-0 group-hover:opacity-100 group-focus-within:opacity-100"
                data-testid={`tier-tooltip-${comp.tier}`}
              >
                {comp.tierLabel} {comp.avgPoints.toFixed(0)}
              </div>
            </div>
          )
        })}

        {/* User marker - the "car" in the race */}
        <div
          className="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 z-10"
          style={{
            left: `${pointsToPercent(userPoints)}%`,
          }}
        >
          {/* Glow effect */}
          <div className="absolute inset-0 w-6 h-6 -m-1.5 bg-tt-green/20" />
          {/* Marker */}
          <div className="relative w-4 h-4 bg-tt-green ring-2 ring-background" />
          {/* Label above */}
          <div className="absolute bottom-full mb-1.5 md:mb-2 left-1/2 -translate-x-1/2 whitespace-nowrap text-center">
            <div className="text-[11px] md:text-xs font-bold uppercase tracking-wide text-tt-green">
              YOU
            </div>
            <div className="text-[11px] md:text-xs font-bold text-tt-green">{userPoints}</div>
          </div>
        </div>
      </div>

      {/* Comparison summary */}
      <div className="grid grid-cols-2 gap-1.5 md:gap-2">
        {comparisons.map((comp) => {
          const isAhead = comp.difference > 0
          const isBehind = comp.difference < 0
          const deltaColorClass = isAhead
            ? 'text-tt-green'
            : isBehind
              ? 'text-destructive'
              : 'text-foreground-dim'
          const config = TIER_CONFIG[comp.tier as Tier] || {
            abbrev: comp.tier,
            color: 'bg-slate-500',
          }

          return (
            <div
              key={comp.tier}
              data-testid={`tier-summary-${comp.tier}`}
              className="flex items-center justify-between p-2 bg-surface-elevated"
            >
              <div className="flex items-center gap-1.5 md:gap-2">
                <div className={`w-2 h-2 ${config.color}`} />
                <span className="text-[11px] md:text-xs uppercase tracking-wide text-foreground-muted">
                  {comp.tierLabel}
                </span>
              </div>
              <div className="text-sm md:text-base font-bold whitespace-nowrap">
                <span className="text-foreground">{comp.avgPoints.toFixed(0)}</span>
                <span className={deltaColorClass}> (</span>
                <span className={deltaColorClass}>
                  {isAhead ? '+' : ''}
                  {comp.difference.toFixed(0)}
                </span>
                <span className={deltaColorClass}>)</span>
              </div>
            </div>
          )
        })}
      </div>

      {/* Rank Impact - Differentials */}
      {playerImpacts &&
        playerImpacts.length > 0 &&
        (() => {
          const gainers = playerImpacts.filter((p) => p.impact > 0)
          const losers = playerImpacts.filter((p) => p.impact < 0)

          return (
            <div className="pt-3 border-t border-border/50">
              {/* Header with tier selector */}
              <div className="flex items-center justify-between mb-3">
                <div className="text-xs uppercase tracking-wide text-foreground-muted">
                  Biggest Swings
                </div>
                {showTierSelector && (
                  <div className="flex items-center gap-1">
                    <span className="text-xs text-foreground-dim mr-1">vs</span>
                    {TIER_OPTIONS.map((tier) => (
                      <button
                        key={tier.value}
                        onClick={() => onTierChange(tier.value)}
                        className={`px-2 py-0.5 text-xs uppercase tracking-wide ${
                          selectedTier === tier.value
                            ? 'bg-tt-green/20 text-tt-green'
                            : 'text-foreground-dim hover:text-foreground hover:bg-surface-elevated'
                        }`}
                      >
                        {tier.label}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              <div key={selectedTier} className="grid grid-cols-2 gap-4">
                {/* Gainers column */}
                <div className="space-y-1.5">
                  <div className="text-xs text-tt-green uppercase tracking-wide mb-1">
                    ▲ Helping You
                  </div>
                  {gainers.length > 0 ? (
                    gainers.map((player) => (
                      <div
                        key={player.playerId}
                        className="flex items-center justify-between text-xs"
                      >
                        <div className="flex items-center gap-1.5 min-w-0">
                          <span className="text-foreground truncate">{player.name}</span>
                          <span className="text-xs shrink-0 text-tt-green">
                            +{player.relativeEO.toFixed(0)}%
                          </span>
                        </div>
                        <span className="font-bold text-tt-green shrink-0 ml-2">
                          +{player.impact.toFixed(1)}
                        </span>
                      </div>
                    ))
                  ) : (
                    <div className="text-xs text-foreground-dim">No gainers</div>
                  )}
                </div>

                {/* Losers column */}
                <div className="space-y-1.5">
                  <div className="text-xs text-destructive uppercase tracking-wide mb-1">
                    ▼ Hurting You
                  </div>
                  {losers.length > 0 ? (
                    losers.map((player) => (
                      <div
                        key={player.playerId}
                        className="flex items-center justify-between text-xs"
                      >
                        <div className="flex items-center gap-1.5 min-w-0">
                          <span
                            className={`truncate ${player.owned ? 'text-foreground' : 'text-foreground-muted'}`}
                          >
                            {player.name}
                          </span>
                          <span className="text-xs shrink-0 text-destructive">
                            {player.relativeEO.toFixed(0)}%
                          </span>
                        </div>
                        <span className="font-bold text-destructive shrink-0 ml-2">
                          {player.impact.toFixed(1)}
                        </span>
                      </div>
                    ))
                  ) : (
                    <div className="text-xs text-foreground-dim">No losers</div>
                  )}
                </div>
              </div>
            </div>
          )
        })()}

      {/* Sample size note */}
      <p className="text-xs text-foreground-dim text-center pt-1.5 md:pt-2">
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
        <div className="text-2xl font-bold text-foreground">~{formatRank(currentRank)}</div>
        <div className="text-xs text-foreground-dim">Est. Rank</div>
      </div>
    )
  }

  const movement = startingRank - currentRank
  const isUp = movement > 0
  const isDown = movement < 0

  return (
    <div className="text-center">
      <div
        className={`text-2xl font-bold flex items-center justify-center gap-1 ${
          isUp ? 'text-tt-green' : isDown ? 'text-destructive' : 'text-foreground'
        }`}
      >
        {isUp && '↑'}
        {isDown && '↓'}
        {formatRank(currentRank)}
      </div>
      <div className="text-xs text-foreground-dim">
        {isUp
          ? `Up from ${formatRank(startingRank)}`
          : isDown
            ? `Down from ${formatRank(startingRank)}`
            : 'Est. Rank'}
      </div>
    </div>
  )
}
