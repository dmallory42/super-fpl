import { useMemo } from 'react'
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

const multiplierStyles: Record<number, string> = {
  0: 'bg-surface text-foreground-dim',
  1: 'bg-fpl-green/30 text-fpl-green border border-fpl-green/30',
  2: 'bg-yellow-500/30 text-yellow-400 border border-yellow-500/30', // Captain
  3: 'bg-fpl-purple/30 text-fpl-purple border border-fpl-purple/30', // Triple Captain
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
  const sortedPlayerIds = useMemo(
    () =>
      Object.keys(effectiveOwnership)
        .map(Number)
        .sort((a, b) => effectiveOwnership[b] - effectiveOwnership[a])
        .slice(0, 30),
    [effectiveOwnership]
  )

  return (
    <div className="overflow-x-auto -mx-4">
      <table className="w-full text-sm min-w-[600px]">
        <thead>
          <tr className="border-b border-border">
            <th className="text-left p-3 font-display text-xs uppercase tracking-wider text-foreground-muted sticky left-0 bg-surface z-10">
              Player
            </th>
            <th className="text-center p-3 font-display text-xs uppercase tracking-wider text-foreground-muted">
              EO%
            </th>
            {managerIds.map((id) => (
              <th
                key={id}
                className="text-center p-3 font-display text-xs uppercase tracking-wider text-foreground-muted max-w-[100px]"
              >
                <span className="truncate block">{managerNames[id] || `#${id}`}</span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {sortedPlayerIds.map((playerId, idx) => {
            const player = players[playerId]
            const eo = effectiveOwnership[playerId]
            const ownership = ownershipMatrix[playerId] || {}

            return (
              <tr
                key={playerId}
                className="border-b border-border hover:bg-surface-hover transition-colors animate-fade-in-up opacity-0"
                style={{ animationDelay: `${idx * 30}ms` }}
              >
                <td className="p-3 sticky left-0 bg-surface z-10">
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-mono text-foreground-muted">
                      {player ? getPositionName(player.position) : ''}
                    </span>
                    <span className="text-foreground font-medium">
                      {player?.web_name || `Player ${playerId}`}
                    </span>
                    <span className="text-xs text-foreground-dim">
                      {player ? teams.get(player.team) : ''}
                    </span>
                  </div>
                </td>
                <td className="text-center p-3">
                  <span
                    className={`font-mono font-bold ${
                      eo >= 80
                        ? 'text-fpl-green'
                        : eo >= 50
                          ? 'text-yellow-400'
                          : 'text-foreground-muted'
                    }`}
                  >
                    {eo.toFixed(0)}%
                  </span>
                </td>
                {managerIds.map((managerId) => {
                  const multiplier = ownership[managerId] || 0
                  return (
                    <td key={managerId} className="text-center p-3">
                      <span
                        className={`
                          inline-flex items-center justify-center
                          w-8 h-8 rounded-lg font-bold text-sm
                          transition-all duration-200
                          ${multiplierStyles[multiplier] || 'bg-surface text-foreground-dim'}
                        `}
                      >
                        {multiplier === 0
                          ? '-'
                          : multiplier === 2
                            ? 'C'
                            : multiplier === 3
                              ? 'TC'
                              : '\u2713'}
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
