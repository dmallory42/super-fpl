import { useState, useMemo } from 'react'
import type { PlayerPrediction } from '../../api/client'
import { getPositionName, formatPrice } from '../../types'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table'

interface PredictionTableProps {
  predictions: PlayerPrediction[]
  teams: Map<number, string>
}

type SortField =
  | 'web_name'
  | 'predicted_points'
  | 'now_cost'
  | 'form'
  | 'total_points'
  | 'confidence'
type SortDir = 'asc' | 'desc'

const difficultyColors: Record<number, string> = {
  1: 'bg-green-600',
  2: 'bg-green-500',
  3: 'bg-yellow-500',
  4: 'bg-orange-500',
  5: 'bg-red-500',
}

export function PredictionTable({ predictions, teams }: PredictionTableProps) {
  const [sortField, setSortField] = useState<SortField>('predicted_points')
  const [sortDir, setSortDir] = useState<SortDir>('desc')
  const [positionFilter, setPositionFilter] = useState<number | null>(null)

  const sortedPredictions = useMemo(() => {
    let filtered = predictions
    if (positionFilter !== null) {
      filtered = predictions.filter((p) => p.position === positionFilter)
    }

    return [...filtered].sort((a, b) => {
      let aVal: number | string = a[sortField] ?? 0
      let bVal: number | string = b[sortField] ?? 0

      if (typeof aVal === 'string') aVal = parseFloat(aVal) || 0
      if (typeof bVal === 'string') bVal = parseFloat(bVal) || 0

      if (sortDir === 'asc') {
        return aVal > bVal ? 1 : -1
      }
      return aVal < bVal ? 1 : -1
    })
  }, [predictions, sortField, sortDir, positionFilter])

  const handleSort = (field: SortField) => {
    if (field === sortField) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortField(field)
      setSortDir('desc')
    }
  }

  const SortIndicator = ({ field }: { field: SortField }) => {
    if (field !== sortField) return null
    return <span className="ml-1">{sortDir === 'asc' ? '↑' : '↓'}</span>
  }

  return (
    <div className="space-y-4">
      <div className="flex gap-2">
        <button
          onClick={() => setPositionFilter(null)}
          className={`px-3 py-1 rounded ${positionFilter === null ? 'bg-green-600' : 'bg-gray-700'}`}
        >
          All
        </button>
        {[1, 2, 3, 4].map((pos) => (
          <button
            key={pos}
            onClick={() => setPositionFilter(pos)}
            className={`px-3 py-1 rounded ${positionFilter === pos ? 'bg-green-600' : 'bg-gray-700'}`}
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
                Name
                <SortIndicator field="web_name" />
              </TableHead>
              <TableHead className="text-gray-300">Team</TableHead>
              <TableHead className="text-gray-300">Fixture</TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('now_cost')}
              >
                Price
                <SortIndicator field="now_cost" />
              </TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('form')}
              >
                Form
                <SortIndicator field="form" />
              </TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('total_points')}
              >
                Season
                <SortIndicator field="total_points" />
              </TableHead>
              <TableHead
                className="text-gray-300 cursor-pointer hover:text-white text-right"
                onClick={() => handleSort('predicted_points')}
              >
                Predicted
                <SortIndicator field="predicted_points" />
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {sortedPredictions.slice(0, 100).map((pred) => (
              <TableRow key={pred.player_id} className="border-gray-700 hover:bg-gray-750">
                <TableCell className="text-gray-400">{getPositionName(pred.position)}</TableCell>
                <TableCell className="text-white font-medium">{pred.web_name}</TableCell>
                <TableCell className="text-gray-400">{teams.get(pred.team) || pred.team}</TableCell>
                <TableCell>
                  {pred.fixture ? (
                    <div className="flex items-center gap-2">
                      <span
                        className={`w-6 h-6 rounded flex items-center justify-center text-xs font-bold ${difficultyColors[pred.fixture.difficulty] || 'bg-gray-600'}`}
                      >
                        {pred.fixture.difficulty}
                      </span>
                      <span className="text-gray-400 text-sm">
                        {pred.fixture.is_home ? 'H' : 'A'} vs{' '}
                        {teams.get(pred.fixture.opponent) || pred.fixture.opponent}
                      </span>
                    </div>
                  ) : (
                    <span className="text-gray-500">-</span>
                  )}
                </TableCell>
                <TableCell className="text-gray-300 text-right">
                  £{formatPrice(pred.now_cost)}m
                </TableCell>
                <TableCell className="text-gray-300 text-right">{pred.form}</TableCell>
                <TableCell className="text-gray-300 text-right">{pred.total_points}</TableCell>
                <TableCell className="text-right">
                  <span className="text-green-400 font-bold">
                    {pred.predicted_points.toFixed(1)}
                  </span>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <div className="text-gray-400 text-sm">
        Showing {Math.min(100, sortedPredictions.length)} of {sortedPredictions.length} players
      </div>
    </div>
  )
}
