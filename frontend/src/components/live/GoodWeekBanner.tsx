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
    <div className="relative overflow-hidden border border-tt-green/40">
      <div className="bg-tt-green/15 px-4 py-3 flex items-center justify-between">
        <div>
          <h3 className="text-lg font-bold uppercase text-tt-green">{message}</h3>
          <p className="text-sm text-foreground-muted mt-0.5">
            {sub} — <span className="font-bold text-tt-green">+{Math.round(margin)}</span> pts ahead
          </p>
        </div>
        {rankMovement != null && rankMovement > 0 && (
          <div className="text-right">
            <span className="text-lg font-bold text-tt-green">{formatRank(rankMovement)}</span>
            <p className="text-sm text-foreground-muted uppercase">
              places gained
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
