import type { Pick, Player, EntryHistory } from '../../types'
import { formatPrice } from '../../types'
import { useMemo } from 'react'
import { StatPanel, StatPanelGrid } from '../ui/StatPanel'

interface SquadStatsProps {
  picks: Pick[]
  players: Map<number, Player>
  entryHistory?: EntryHistory
}

export function SquadStats({ picks, players, entryHistory }: SquadStatsProps) {
  const stats = useMemo(() => {
    let totalValue = 0
    let totalPoints = 0

    for (const pick of picks) {
      const player = players.get(pick.element)
      if (!player) continue
      totalValue += player.now_cost
      totalPoints += player.total_points
    }

    return {
      totalValue,
      totalPoints,
      bank: entryHistory?.bank || 0,
      overallRank: entryHistory?.overall_rank,
      gwPoints: entryHistory?.points,
    }
  }, [picks, players, entryHistory])

  return (
    <StatPanelGrid>
      <StatPanel
        label="Squad Value"
        value={`£${formatPrice(stats.totalValue)}m`}
        animationDelay={0}
      />
      <StatPanel
        label="In the Bank"
        value={`£${formatPrice(stats.bank)}m`}
        animationDelay={50}
      />
      <StatPanel
        label="Total Value"
        value={`£${formatPrice(stats.totalValue + stats.bank)}m`}
        animationDelay={100}
      />
      {stats.gwPoints !== undefined && (
        <StatPanel
          label="GW Points"
          value={stats.gwPoints.toString()}
          highlight
          animationDelay={150}
        />
      )}
    </StatPanelGrid>
  )
}
