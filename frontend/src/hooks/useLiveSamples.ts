import { useQuery } from '@tanstack/react-query'
import { fetchLiveSamples, type LiveSamplesResponse } from '../api/client'

export function useLiveSamples(gameweek: number | null) {
  return useQuery({
    queryKey: ['live-samples', gameweek],
    queryFn: () => fetchLiveSamples(gameweek!),
    enabled: gameweek !== null && gameweek > 0,
    refetchInterval: 60000, // Refetch every minute during live
    staleTime: 30000,
  })
}

export interface TierComparison {
  tier: string
  tierLabel: string
  avgPoints: number
  sampleSize: number
  difference: number // positive = you're ahead
}

/**
 * Calculate comparison data between user points and tier averages
 */
export function calculateComparisons(
  userPoints: number,
  samplesData: LiveSamplesResponse | undefined
): TierComparison[] {
  if (!samplesData?.samples) return []

  const tierLabels: Record<string, string> = {
    top_10k: 'Top 10K',
    top_100k: 'Top 100K',
    top_1m: 'Top 1M',
    overall: 'Overall',
  }

  const comparisons: TierComparison[] = []

  for (const [tier, data] of Object.entries(samplesData.samples)) {
    if (data) {
      comparisons.push({
        tier,
        tierLabel: tierLabels[tier] || tier,
        avgPoints: data.avg_points,
        sampleSize: data.sample_size,
        difference: userPoints - data.avg_points,
      })
    }
  }

  // Sort by tier importance (top_10k first)
  const tierOrder = ['top_10k', 'top_100k', 'top_1m', 'overall']
  comparisons.sort((a, b) => tierOrder.indexOf(a.tier) - tierOrder.indexOf(b.tier))

  return comparisons
}

/**
 * Get effective ownership for a player from sample data
 */
export function getPlayerEO(
  playerId: number,
  tier: string,
  samplesData: LiveSamplesResponse | undefined
): number | null {
  const tierData = samplesData?.samples?.[tier as keyof typeof samplesData.samples]
  if (!tierData?.effective_ownership) return null
  return tierData.effective_ownership[playerId] ?? null
}

/**
 * Estimate live rank based on user points vs tier averages.
 * Uses linear interpolation between tier boundaries.
 */
export function estimateLiveRank(
  userPoints: number,
  samplesData: LiveSamplesResponse | undefined
): { rank: number; tier: string; confidence: 'high' | 'medium' | 'low' } | null {
  if (!samplesData?.samples) return null

  const top10k = samplesData.samples.top_10k?.avg_points ?? 0
  const top100k = samplesData.samples.top_100k?.avg_points ?? 0
  const top1m = samplesData.samples.top_1m?.avg_points ?? 0
  const overall = samplesData.samples.overall?.avg_points ?? 0

  // If all zeros, can't estimate
  if (top10k === 0 && overall === 0) return null

  // Determine confidence based on whether we have real samples
  const isEstimated = samplesData.samples.top_10k?.estimated ?? false
  const confidence = isEstimated ? 'low' : 'medium'

  // Estimate rank based on which tier the user falls into
  if (userPoints >= top10k) {
    // In top 10k - estimate position within
    // Assume linear distribution from rank 1 (points = top10k * 1.3) to rank 10000 (points = top10k)
    const topPoints = top10k * 1.3
    const ratio = Math.max(0, Math.min(1, (topPoints - userPoints) / (topPoints - top10k)))
    const rank = Math.max(1, Math.round(ratio * 10000))
    return { rank, tier: 'top_10k', confidence }
  }

  if (userPoints >= top100k) {
    // Between 10k and 100k
    const ratio = (top10k - userPoints) / (top10k - top100k)
    const rank = Math.round(10000 + ratio * 90000)
    return { rank, tier: 'top_100k', confidence }
  }

  if (userPoints >= top1m) {
    // Between 100k and 1M
    const ratio = (top100k - userPoints) / (top100k - top1m)
    const rank = Math.round(100000 + ratio * 900000)
    return { rank, tier: 'top_1m', confidence }
  }

  if (userPoints >= overall) {
    // Between 1M and ~5M (average)
    const ratio = (top1m - userPoints) / (top1m - overall)
    const rank = Math.round(1000000 + ratio * 4000000)
    return { rank, tier: 'overall', confidence: 'low' }
  }

  // Below average - estimate based on how far below
  const ratio = Math.max(0, userPoints / overall)
  const rank = Math.round(5000000 + (1 - ratio) * 5000000)
  return { rank: Math.min(rank, 10000000), tier: 'below_average', confidence: 'low' }
}
