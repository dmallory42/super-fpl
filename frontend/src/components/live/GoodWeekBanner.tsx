import { formatRank } from '../../lib/format'

interface GoodWeekBannerProps {
  margin: number // points above top-10k average
  rankMovement?: number | null // positive = improved (places gained)
}

function getTier(margin: number): { message: string; sub: string } {
  if (margin >= 30) return { message: 'Legendary Week!', sub: 'Absolutely demolishing the top 10k' }
  if (margin >= 20) return { message: 'Outstanding!', sub: 'Well clear of the top 10k average' }
  return { message: 'Great Week!', sub: 'Beating the top 10k average' }
}

export function GoodWeekBanner({ margin, rankMovement }: GoodWeekBannerProps) {
  if (margin < 10) return null

  const { message, sub } = getTier(margin)

  return (
    <div className="animate-fade-in-up relative overflow-hidden rounded-lg border border-fpl-green/40 animate-pulse-glow">
      <div className="bg-gradient-to-r from-fpl-green/20 via-fpl-green/10 to-fpl-green/20 px-4 py-3 flex items-center justify-between">
        <div>
          <h3 className="font-display text-lg font-bold tracking-wider uppercase text-fpl-green">
            {message}
          </h3>
          <p className="text-xs text-foreground-muted mt-0.5">
            {sub} —{' '}
            <span className="font-mono font-bold text-fpl-green">+{Math.round(margin)}</span> pts
            ahead
          </p>
        </div>
        {rankMovement != null && rankMovement > 0 && (
          <div className="text-right">
            <span className="font-mono text-lg font-bold text-fpl-green">
              {formatRank(rankMovement)}
            </span>
            <p className="text-[10px] text-foreground-muted font-display uppercase tracking-wider">
              places gained
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
