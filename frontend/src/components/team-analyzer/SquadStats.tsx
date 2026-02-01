import type { Pick, Player, EntryHistory } from '../../types'
import { formatPrice } from '../../types'
import { useMemo } from 'react'

interface SquadStatsProps {
  picks: Pick[]
  players: Map<number, Player>
  entryHistory?: EntryHistory
}

export function SquadStats({ picks, players, entryHistory }: SquadStatsProps) {
  const stats = useMemo(() => {
    const startingPicks = picks.filter(p => p.position <= 11)

    let totalValue = 0
    let totalPoints = 0
    let totalForm = 0
    let playerCount = 0

    for (const pick of picks) {
      const player = players.get(pick.element)
      if (!player) continue
      totalValue += player.now_cost
      totalPoints += player.total_points
      totalForm += parseFloat(String(player.form || 0))
      playerCount++
    }

    const startingPoints = startingPicks.reduce((sum, p) => {
      const player = players.get(p.element)
      return sum + (player?.total_points || 0)
    }, 0)

    return {
      totalValue,
      totalPoints,
      startingPoints,
      avgForm: playerCount > 0 ? (totalForm / playerCount).toFixed(1) : '0.0',
      bank: entryHistory?.bank || 0,
      overallRank: entryHistory?.overall_rank,
      gwPoints: entryHistory?.points,
      gwRank: entryHistory?.rank,
    }
  }, [picks, players, entryHistory])

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      <StatCard label="Squad Value" value={`£${formatPrice(stats.totalValue)}m`} />
      <StatCard label="In the Bank" value={`£${formatPrice(stats.bank)}m`} />
      <StatCard label="Total Value" value={`£${formatPrice(stats.totalValue + stats.bank)}m`} />
      <StatCard label="Avg Form" value={stats.avgForm} />

      {stats.gwPoints !== undefined && (
        <StatCard label="GW Points" value={stats.gwPoints.toString()} highlight />
      )}
      {stats.gwRank !== undefined && stats.gwRank !== null && (
        <StatCard label="GW Rank" value={stats.gwRank.toLocaleString()} />
      )}
      {stats.overallRank !== undefined && (
        <StatCard label="Overall Rank" value={stats.overallRank.toLocaleString()} />
      )}
      <StatCard label="Total Points" value={stats.totalPoints.toString()} />
    </div>
  )
}

interface StatCardProps {
  label: string
  value: string
  highlight?: boolean
}

function StatCard({ label, value, highlight }: StatCardProps) {
  return (
    <div className={`p-4 rounded-lg ${highlight ? 'bg-green-900/50 border border-green-500/30' : 'bg-gray-800'}`}>
      <div className="text-sm text-gray-400">{label}</div>
      <div className={`text-xl font-bold ${highlight ? 'text-green-400' : 'text-white'}`}>{value}</div>
    </div>
  )
}
