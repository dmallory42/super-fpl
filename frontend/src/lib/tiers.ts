export type Tier = 'top_10k' | 'top_100k' | 'top_1m' | 'overall'

export const TIER_OPTIONS: { value: Tier; label: string }[] = [
  { value: 'top_10k', label: '10K' },
  { value: 'top_100k', label: '100K' },
  { value: 'top_1m', label: '1M' },
  { value: 'overall', label: 'All' },
]

export const TIER_LABELS: Record<Tier, string> = {
  top_10k: 'Top 10K',
  top_100k: 'Top 100K',
  top_1m: 'Top 1M',
  overall: 'Overall',
}

export const TIER_CONFIG: Record<Tier, { abbrev: string; color: string }> = {
  top_10k: { abbrev: '10K', color: 'bg-amber-500' },
  top_100k: { abbrev: '100K', color: 'bg-purple-500' },
  top_1m: { abbrev: '1M', color: 'bg-blue-500' },
  overall: { abbrev: 'AVG', color: 'bg-slate-500' },
}

export const TIER_ORDER: Tier[] = ['top_10k', 'top_100k', 'top_1m', 'overall']
