import { useState, useMemo } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { getPositionName, formatPrice } from '../types'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'

type SortField = 'web_name' | 'total_points' | 'now_cost' | 'form' | 'selected_by_percent'
type SortDir = 'asc' | 'desc'

export function Home() {
  const { data, isLoading, error } = usePlayers()
  const [sortField, setSortField] = useState<SortField>('total_points')
  const [sortDir, setSortDir] = useState<SortDir>('desc')
  const [positionFilter, setPositionFilter] = useState<number | null>(null)

  const teamMap = useMemo(() => {
    if (!data?.teams) return new Map<number, string>()
    return new Map(data.teams.map(t => [t.id, t.short_name]))
  }, [data?.teams])

  const sortedPlayers = useMemo(() => {
    if (!data?.players) return []

    let filtered = data.players
    if (positionFilter !== null) {
      filtered = filtered.filter(p => p.element_type === positionFilter)
    }

    return [...filtered].sort((a, b) => {
      let aVal: number | string = a[sortField]
      let bVal: number | string = b[sortField]

      // Handle string values that should be compared as numbers
      if (typeof aVal === 'string') aVal = parseFloat(aVal) || 0
      if (typeof bVal === 'string') bVal = parseFloat(bVal) || 0

      if (sortDir === 'asc') {
        return aVal > bVal ? 1 : -1
      }
      return aVal < bVal ? 1 : -1
    })
  }, [data?.players, sortField, sortDir, positionFilter])

  const handleSort = (field: SortField) => {
    if (field === sortField) {
      setSortDir(d => d === 'asc' ? 'desc' : 'asc')
    } else {
      setSortField(field)
      setSortDir('desc')
    }
  }

  const SortIndicator = ({ field }: { field: SortField }) => {
    if (field !== sortField) return null
    return <span className="ml-1">{sortDir === 'asc' ? '↑' : '↓'}</span>
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-gray-400">Loading players...</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-red-400">Error loading players: {error.message}</div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex gap-2">
        <button
          onClick={() => setPositionFilter(null)}
          className={`px-3 py-1 rounded ${positionFilter === null ? 'bg-blue-600' : 'bg-gray-700'}`}
        >
          All
        </button>
        {[1, 2, 3, 4].map(pos => (
          <button
            key={pos}
            onClick={() => setPositionFilter(pos)}
            className={`px-3 py-1 rounded ${positionFilter === pos ? 'bg-blue-600' : 'bg-gray-700'}`}
          >
            {getPositionName(pos)}
          </button>
        ))}
      </div>

      <div className="bg-gray-800 rounded-lg overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="border-gray-700">
              <TableHead className="text-gray-300">Pos</TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white"
                onClick={() => handleSort('web_name')}
              >
                Name<SortIndicator field="web_name" />
              </TableHead>
              <TableHead className="text-gray-300">Team</TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('now_cost')}
              >
                Price<SortIndicator field="now_cost" />
              </TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('total_points')}
              >
                Points<SortIndicator field="total_points" />
              </TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('form')}
              >
                Form<SortIndicator field="form" />
              </TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('selected_by_percent')}
              >
                Selected<SortIndicator field="selected_by_percent" />
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {sortedPlayers.slice(0, 100).map(player => (
              <TableRow key={player.id} className="border-gray-700 hover:bg-gray-750">
                <TableCell className="text-gray-400">{getPositionName(player.element_type)}</TableCell>
                <TableCell className="text-white font-medium">{player.web_name}</TableCell>
                <TableCell className="text-gray-400">{teamMap.get(player.team) || player.team}</TableCell>
                <TableCell className="text-gray-300 text-right">£{formatPrice(player.now_cost)}m</TableCell>
                <TableCell className="text-white font-bold text-right">{player.total_points}</TableCell>
                <TableCell className="text-gray-300 text-right">{player.form}</TableCell>
                <TableCell className="text-gray-300 text-right">{player.selected_by_percent}%</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <div className="text-gray-400 text-sm">
        Showing {Math.min(100, sortedPlayers.length)} of {sortedPlayers.length} players
      </div>
    </div>
  )
}
