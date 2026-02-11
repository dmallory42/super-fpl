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
  1: 'bg-gradient-to-br from-amber-400 to-amber-600 text-amber-950',
  2: 'bg-gradient-to-br from-slate-300 to-slate-400 text-slate-800',
  3: 'bg-gradient-to-br from-orange-400 to-orange-600 text-orange-950',
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
          <span className="text-xs font-display uppercase tracking-wide text-fpl-green">
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
                  className={`px-2 py-0.5 text-xs font-display uppercase tracking-wide rounded transition-colors ${
                    activeTier === tier.value
                      ? 'bg-fpl-purple/20 text-fpl-purple'
                      : 'text-foreground-dim hover:text-foreground hover:bg-surface-elevated'
                  }`}
                >
                  {tier.label}
                </button>
              ))}
            </>
          ) : (
            <span className="text-xs font-display uppercase tracking-wide text-foreground-muted">
              vs {activeTierLabel}
            </span>
          )}
        </div>
      </div>

      {/* Captain list */}
      <div key={activeTier} className="space-y-1.5">
        {captainOptions.length > 0 &&
          captainOptions.map((captain, idx) => {
            const barWidth = maxCaptaincy > 0 ? (captain.captainPct / maxCaptaincy) * 100 : 0

            return (
              <div
                key={captain.playerId}
                className={`
                  relative p-2 rounded-lg text-sm overflow-hidden
                  animate-fade-in-up-fast opacity-0
                  ${
                    captain.isUserCaptain
                      ? 'bg-fpl-green/10 ring-1 ring-fpl-green/30'
                      : 'bg-surface-elevated'
                  }
                `}
                style={{ animationDelay: `${idx * 40}ms` }}
              >
                {/* Captaincy bar with gradient */}
                <div
                  className={`
                    absolute inset-y-0 left-0 transition-all duration-500 ease-out
                    ${
                      captain.isUserCaptain
                        ? 'bg-gradient-to-r from-fpl-green/30 to-fpl-green/10'
                        : 'bg-gradient-to-r from-fpl-purple/25 to-fpl-purple/5'
                    }
                  `}
                  style={{ width: `${barWidth}%` }}
                />

                {/* Content */}
                <div className="relative flex items-center gap-2">
                  {/* Rank badge */}
                  <span
                    className={`
                    inline-flex items-center justify-center w-5 h-5 rounded-full text-[9px] font-mono font-bold shrink-0
                    ${rankBadgeStyles[captain.rank] || 'bg-foreground/10 text-foreground-muted'}
                  `}
                  >
                    {captain.rank}
                  </span>

                  {/* Name */}
                  <div className="flex items-center gap-1.5 min-w-0 flex-1">
                    {captain.isUserCaptain && <span className="text-yellow-400 text-xs">©</span>}
                    <span
                      className={`truncate ${captain.isUserCaptain ? 'text-fpl-green font-medium' : 'text-foreground'}`}
                    >
                      {captain.name}
                    </span>
                  </div>

                  {/* Percentage */}
                  <span
                    className={`font-mono font-bold text-base shrink-0 ${captain.isUserCaptain ? 'text-fpl-green' : 'text-fpl-purple'}`}
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
