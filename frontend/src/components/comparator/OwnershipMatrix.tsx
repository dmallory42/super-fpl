import type { ComparisonPlayer } from '../../api/client'
import { getPositionName } from '../../types'

interface OwnershipMatrixProps {
  effectiveOwnership: Record<number, number>
  ownershipMatrix: Record<number, Record<number, number>>
  players: Record<number, ComparisonPlayer>
  managerIds: number[]
  managerNames: Record<number, string>
  teams: Map<number, string>
}

const multiplierColors: Record<number, string> = {
  0: 'bg-gray-800',
  1: 'bg-green-900',
  2: 'bg-yellow-900', // Captain
  3: 'bg-purple-900', // Triple Captain
}

export function OwnershipMatrix({
  effectiveOwnership,
  ownershipMatrix,
  players,
  managerIds,
  managerNames,
  teams,
}: OwnershipMatrixProps) {
  // Sort players by EO descending
  const sortedPlayerIds = Object.keys(effectiveOwnership)
    .map(Number)
    .sort((a, b) => effectiveOwnership[b] - effectiveOwnership[a])
    .slice(0, 30) // Top 30 players

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-gray-700">
            <th className="text-left p-2 text-gray-400">Player</th>
            <th className="text-center p-2 text-gray-400">EO%</th>
            {managerIds.map(id => (
              <th key={id} className="text-center p-2 text-gray-400 max-w-[100px] truncate">
                {managerNames[id] || `#${id}`}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {sortedPlayerIds.map(playerId => {
            const player = players[playerId]
            const eo = effectiveOwnership[playerId]
            const ownership = ownershipMatrix[playerId] || {}

            return (
              <tr key={playerId} className="border-b border-gray-800 hover:bg-gray-800/50">
                <td className="p-2">
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-gray-500">
                      {player ? getPositionName(player.position) : ''}
                    </span>
                    <span className="text-white font-medium">
                      {player?.web_name || `Player ${playerId}`}
                    </span>
                    <span className="text-xs text-gray-500">
                      {player ? teams.get(player.team) : ''}
                    </span>
                  </div>
                </td>
                <td className="text-center p-2">
                  <span className={`font-bold ${eo >= 80 ? 'text-green-400' : eo >= 50 ? 'text-yellow-400' : 'text-gray-400'}`}>
                    {eo.toFixed(0)}%
                  </span>
                </td>
                {managerIds.map(managerId => {
                  const multiplier = ownership[managerId] || 0
                  return (
                    <td key={managerId} className="text-center p-2">
                      <span className={`inline-block w-8 h-8 rounded flex items-center justify-center text-white font-bold ${multiplierColors[multiplier] || 'bg-gray-800'}`}>
                        {multiplier === 0 ? '-' : multiplier === 2 ? 'C' : multiplier === 3 ? 'TC' : '✓'}
                      </span>
                    </td>
                  )
                })}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
