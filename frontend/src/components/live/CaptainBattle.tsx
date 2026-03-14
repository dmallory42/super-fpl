import { useState, useMemo } from 'react'
import type { TierSampleData } from '../../api/client'
import { type Tier, TIER_OPTIONS, TIER_LABELS } from '../../lib/tiers'

interface CaptainBattleProps {
  userCaptainId: number | undefined
  samples:
    | {
        top_10k?: TierSampleData
        top_100k?: TierSampleData
        top_1m?: TierSampleData
        overall?: TierSampleData
      }
    | undefined
  playersMap: Map<number, { web_name: string; team: number; element_type: number }>
  selectedTier?: Tier
  onTierChange?: (tier: Tier) => void
  showTierSelector?: boolean
}

interface CaptainOption {
  playerId: number
  name: string
  captainPct: number
  isUserCaptain: boolean
  rank: number
}

// Rank badge styles (1st, 2nd, 3rd get special treatment)
const rankBadgeStyles: Record<number, string> = {
  1: 'bg-amber-400 text-amber-950',
  2: 'bg-slate-300 text-slate-800',
  3: 'bg-orange-400 text-orange-950',
}

export function CaptainBattle({
  userCaptainId,
  samples,
  playersMap,
  selectedTier,
  onTierChange,
  showTierSelector = true,
}: CaptainBattleProps) {
  const [internalTier, setInternalTier] = useState<Tier>('top_10k')
  const activeTier = selectedTier ?? internalTier
  const handleTierChange = onTierChange ?? setInternalTier
  const activeTierLabel = TIER_LABELS[activeTier] ?? activeTier

  const tierData = samples?.[activeTier]
  const captainPercent = tierData?.captain_percent

  const captainOptions = useMemo((): CaptainOption[] => {
    if (!captainPercent) return []

    const candidates: {
      playerId: number
      name: string
      captainPct: number
      isUserCaptain: boolean
    }[] = []

    for (const [playerIdStr, capPct] of Object.entries(captainPercent)) {
      const playerId = parseInt(playerIdStr, 10)
      const info = playersMap.get(playerId)
      if (!info) continue

      candidates.push({
        playerId,
        name: info.web_name,
        captainPct: capPct,
        isUserCaptain: playerId === userCaptainId,
      })
    }

    // Always include user's captain if not already added
    if (userCaptainId && !candidates.find((c) => c.playerId === userCaptainId)) {
      const info = playersMap.get(userCaptainId)
      if (info) {
        candidates.push({
          playerId: userCaptainId,
          name: info.web_name,
          captainPct: 0,
          isUserCaptain: true,
        })
      }
    }

    // Sort by captaincy rate and add rank
    const sorted = candidates
      .sort((a, b) => b.captainPct - a.captainPct)
      .slice(0, 6)
      .map((c, idx) => ({ ...c, rank: idx + 1 }))

    return sorted
  }, [captainPercent, playersMap, userCaptainId])

  if (!samples) {
    return (
      <div className="text-center text-foreground-muted py-4 text-sm">
        No captain data available
      </div>
    )
  }

  // Find max captaincy for bar scaling
  const maxCaptaincy =
    captainOptions.length > 0 ? Math.max(...captainOptions.map((c) => c.captainPct)) : 100

  // Check if user's captain is a differential (not in top 3)
  const userCaptainRank = captainOptions.find((c) => c.isUserCaptain)?.rank ?? null
  const isDifferential = userCaptainRank !== null && userCaptainRank > 3

  return (
    <div className="space-y-2.5 md:space-y-3">
      {/* Tier selector */}
      <div className="flex items-center justify-between">
        {isDifferential && (
          <span className="text-xs uppercase tracking-wide text-tt-green">
            Captain differential
          </span>
        )}
        <div className={`flex items-center gap-1 ${!isDifferential ? 'ml-auto' : ''}`}>
          {showTierSelector ? (
            <>
              <span className="text-xs text-foreground-dim mr-1">vs</span>
              {TIER_OPTIONS.map((tier) => (
                <button
                  key={tier.value}
                  onClick={() => handleTierChange(tier.value)}
                  className={`px-2 py-0.5 text-xs uppercase tracking-wide ${
                    activeTier === tier.value
                      ? 'bg-tt-magenta/20 text-tt-magenta'
                      : 'text-foreground-dim hover:text-foreground hover:bg-surface-elevated'
                  }`}
                >
                  {tier.label}
                </button>
              ))}
            </>
          ) : (
            <span className="text-xs uppercase tracking-wide text-foreground-muted">
              vs {activeTierLabel}
            </span>
          )}
        </div>
      </div>

      {/* Captain list */}
      <div key={activeTier} className="space-y-1.5">
        {captainOptions.length > 0 &&
          captainOptions.map((captain) => {
            const barWidth = maxCaptaincy > 0 ? (captain.captainPct / maxCaptaincy) * 100 : 0

            return (
              <div
                key={captain.playerId}
                className={`
                  relative p-2 text-sm overflow-hidden
                  ${
                    captain.isUserCaptain
                      ? 'bg-tt-green/10 ring-1 ring-tt-green/30'
                      : 'bg-surface-elevated'
                  }
                `}
              >
                {/* Captaincy bar */}
                <div
                  className={`
                    absolute inset-y-0 left-0
                    ${captain.isUserCaptain ? 'bg-tt-green/20' : 'bg-tt-magenta/15'}
                  `}
                  style={{ width: `${barWidth}%` }}
                />

                {/* Content */}
                <div className="relative flex items-center gap-2">
                  {/* Rank badge */}
                  <span
                    className={`
                    inline-flex items-center justify-center w-5 h-5 text-[9px] font-bold shrink-0
                    ${rankBadgeStyles[captain.rank] || 'bg-foreground/10 text-foreground-muted'}
                  `}
                  >
                    {captain.rank}
                  </span>

                  {/* Name */}
                  <div className="flex items-center gap-1.5 min-w-0 flex-1">
                    {captain.isUserCaptain && <span className="text-tt-yellow text-xs">©</span>}
                    <span
                      className={`truncate ${captain.isUserCaptain ? 'text-tt-green font-medium' : 'text-foreground'}`}
                    >
                      {captain.name}
                    </span>
                  </div>

                  {/* Percentage */}
                  <span
                    className={`font-bold text-base shrink-0 ${captain.isUserCaptain ? 'text-tt-green' : 'text-tt-magenta'}`}
                  >
                    {captain.captainPct.toFixed(0)}%
                  </span>
                </div>
              </div>
            )
          })}
      </div>
    </div>
  )
}
