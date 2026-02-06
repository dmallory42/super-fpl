/**
 * Format a rank number into a compact human-readable string.
 * Handles null for cases where rank is unavailable.
 */
export function formatRank(rank: number | null): string {
  if (rank === null) return '-'
  if (rank >= 1000000) return `${(rank / 1000000).toFixed(1)}M`
  if (rank >= 10000) return `${Math.round(rank / 1000)}K`
  if (rank >= 1000) return `${(rank / 1000).toFixed(1)}K`
  return rank.toLocaleString()
}
