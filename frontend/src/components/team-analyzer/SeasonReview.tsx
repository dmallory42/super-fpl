import { useMemo } from 'react'
import type { ManagerHistoryResponse } from '../../api/client'
import type { EntryHistory } from '../../types'

interface SeasonReviewProps {
  history: ManagerHistoryResponse | null
}

interface ChipInfo {
  name: string
  displayName: string
  event: number
}

const chipDisplayNames: Record<string, string> = {
  wildcard: 'Wildcard',
  bboost: 'Bench Boost',
  '3xc': 'Triple Captain',
  freehit: 'Free Hit',
}

function RankChart({ gameweeks }: { gameweeks: EntryHistory[] }) {
  if (gameweeks.length === 0) return null

  const ranks = gameweeks.map(gw => gw.overall_rank)
  const maxRank = Math.max(...ranks)
  const minRank = Math.min(...ranks)
  const range = maxRank - minRank || 1

  const width = 600
  const height = 200
  const padding = { top: 20, right: 40, bottom: 30, left: 60 }
  const chartWidth = width - padding.left - padding.right
  const chartHeight = height - padding.top - padding.bottom

  const points = gameweeks.map((gw, i) => {
    const x = padding.left + (i / (gameweeks.length - 1 || 1)) * chartWidth
    // Invert y so lower rank (better) is higher on chart
    const y = padding.top + ((gw.overall_rank - minRank) / range) * chartHeight
    return { x, y, rank: gw.overall_rank, event: gw.event }
  })

  const pathData = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ')

  const formatRank = (rank: number) => {
    if (rank >= 1000000) return `${(rank / 1000000).toFixed(1)}M`
    if (rank >= 1000) return `${(rank / 1000).toFixed(0)}K`
    return rank.toString()
  }

  return (
    <div className="bg-gray-800 rounded-lg p-4">
      <h3 className="text-lg font-semibold text-white mb-4">Rank Progression</h3>
      <svg viewBox={`0 0 ${width} ${height}`} className="w-full max-w-2xl mx-auto">
        {/* Y-axis labels */}
        <text x={padding.left - 10} y={padding.top} textAnchor="end" className="fill-gray-400 text-xs">
          {formatRank(minRank)}
        </text>
        <text x={padding.left - 10} y={height - padding.bottom} textAnchor="end" className="fill-gray-400 text-xs">
          {formatRank(maxRank)}
        </text>

        {/* X-axis labels */}
        {gameweeks.length <= 10 && gameweeks.map((gw, i) => (
          <text
            key={gw.event}
            x={padding.left + (i / (gameweeks.length - 1 || 1)) * chartWidth}
            y={height - 5}
            textAnchor="middle"
            className="fill-gray-400 text-xs"
          >
            {gw.event}
          </text>
        ))}

        {/* Grid lines */}
        <line
          x1={padding.left}
          y1={padding.top}
          x2={padding.left}
          y2={height - padding.bottom}
          className="stroke-gray-700"
        />
        <line
          x1={padding.left}
          y1={height - padding.bottom}
          x2={width - padding.right}
          y2={height - padding.bottom}
          className="stroke-gray-700"
        />

        {/* Rank line */}
        <path d={pathData} fill="none" className="stroke-emerald-500 stroke-2" />

        {/* Data points */}
        {points.map((p, i) => (
          <circle key={i} cx={p.x} cy={p.y} r={4} className="fill-emerald-500" />
        ))}
      </svg>
    </div>
  )
}

function SeasonStats({ gameweeks }: { gameweeks: EntryHistory[] }) {
  const stats = useMemo(() => {
    if (gameweeks.length === 0) return null

    const points = gameweeks.map(gw => gw.points)
    const totalPoints = gameweeks[gameweeks.length - 1].total_points
    const bestGW = Math.max(...points)
    const worstGW = Math.min(...points)
    const avgPoints = points.reduce((a, b) => a + b, 0) / points.length

    const bestGWEvent = gameweeks.find(gw => gw.points === bestGW)?.event
    const worstGWEvent = gameweeks.find(gw => gw.points === worstGW)?.event

    return { totalPoints, bestGW, worstGW, avgPoints, bestGWEvent, worstGWEvent }
  }, [gameweeks])

  if (!stats) return null

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div className="bg-gray-800 rounded-lg p-4 text-center">
        <div className="text-gray-400 text-sm">Total Points</div>
        <div className="text-2xl font-bold text-emerald-400">{stats.totalPoints}</div>
      </div>
      <div className="bg-gray-800 rounded-lg p-4 text-center">
        <div className="text-gray-400 text-sm">Best GW</div>
        <div className="text-2xl font-bold text-green-400">{stats.bestGW}</div>
        <div className="text-xs text-gray-500">GW{stats.bestGWEvent}</div>
      </div>
      <div className="bg-gray-800 rounded-lg p-4 text-center">
        <div className="text-gray-400 text-sm">Worst GW</div>
        <div className="text-2xl font-bold text-red-400">{stats.worstGW}</div>
        <div className="text-xs text-gray-500">GW{stats.worstGWEvent}</div>
      </div>
      <div className="bg-gray-800 rounded-lg p-4 text-center">
        <div className="text-gray-400 text-sm">Avg Per GW</div>
        <div className="text-2xl font-bold text-blue-400">{stats.avgPoints.toFixed(1)}</div>
      </div>
    </div>
  )
}

function GameweekTable({ gameweeks }: { gameweeks: EntryHistory[] }) {
  const formatRank = (rank: number | null) => {
    if (rank === null) return '-'
    if (rank >= 1000000) return `${(rank / 1000000).toFixed(2)}M`
    if (rank >= 1000) return `${Math.round(rank / 1000)}K`
    return rank.toLocaleString()
  }

  return (
    <div className="bg-gray-800 rounded-lg overflow-hidden">
      <h3 className="text-lg font-semibold text-white p-4 border-b border-gray-700">Gameweek Breakdown</h3>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-700 text-gray-300">
            <tr>
              <th className="px-4 py-2 text-left">GW</th>
              <th className="px-4 py-2 text-right">Points</th>
              <th className="px-4 py-2 text-right">Total</th>
              <th className="px-4 py-2 text-right">Rank</th>
              <th className="px-4 py-2 text-right">Transfers</th>
              <th className="px-4 py-2 text-right">Hits</th>
              <th className="px-4 py-2 text-right">Bench</th>
            </tr>
          </thead>
          <tbody>
            {[...gameweeks].reverse().map(gw => (
              <tr key={gw.event} className="border-b border-gray-700 hover:bg-gray-750">
                <td className="px-4 py-2 font-medium text-white">GW{gw.event}</td>
                <td className="px-4 py-2 text-right text-emerald-400">{gw.points}</td>
                <td className="px-4 py-2 text-right text-gray-300">{gw.total_points}</td>
                <td className="px-4 py-2 text-right text-gray-300">{formatRank(gw.overall_rank)}</td>
                <td className="px-4 py-2 text-right text-gray-300">{gw.event_transfers}</td>
                <td className="px-4 py-2 text-right text-red-400">
                  {gw.event_transfers_cost > 0 ? `-${gw.event_transfers_cost}` : '-'}
                </td>
                <td className="px-4 py-2 text-right text-yellow-400">{gw.points_on_bench}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function ChipsTimeline({ chips }: { chips: ChipInfo[] }) {
  return (
    <div className="bg-gray-800 rounded-lg p-4">
      <h3 className="text-lg font-semibold text-white mb-4">Chips Used</h3>
      {chips.length === 0 ? (
        <p className="text-gray-400 text-sm">No chips used yet</p>
      ) : (
        <div className="flex flex-wrap gap-3">
          {chips.map((chip, i) => (
            <div
              key={i}
              className="bg-purple-900/50 border border-purple-500/30 rounded-lg px-4 py-2"
            >
              <div className="text-purple-300 font-medium">{chip.displayName}</div>
              <div className="text-xs text-purple-400">GW {chip.event}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

export function SeasonReview({ history }: SeasonReviewProps) {
  const processedChips: ChipInfo[] = useMemo(() => {
    if (!history?.chips) return []
    return history.chips.map(chip => ({
      name: chip.name,
      displayName: chipDisplayNames[chip.name] || chip.name,
      event: chip.event,
    }))
  }, [history?.chips])

  if (!history || !history.current || history.current.length === 0) {
    return (
      <div className="bg-gray-800 rounded-lg p-8 text-center">
        <p className="text-gray-400">No season data available</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <SeasonStats gameweeks={history.current} />
      <RankChart gameweeks={history.current} />
      <GameweekTable gameweeks={history.current} />
      <ChipsTimeline chips={processedChips} />
    </div>
  )
}
