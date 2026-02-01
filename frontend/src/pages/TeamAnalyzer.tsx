import { useState, useMemo } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { useManager, useManagerPicks } from '../hooks/useManager'
import { ManagerSearch } from '../components/team-analyzer/ManagerSearch'
import { SquadPitch } from '../components/team-analyzer/SquadPitch'
import { SquadStats } from '../components/team-analyzer/SquadStats'

export function TeamAnalyzer() {
  const [managerId, setManagerId] = useState<number | null>(null)
  const { data: playersData, isLoading: playersLoading } = usePlayers()
  const { data: manager, isLoading: managerLoading, error: managerError } = useManager(managerId)
  const { data: picks, isLoading: picksLoading, error: picksError } = useManagerPicks(
    managerId,
    manager?.current_event ?? null
  )

  const playersMap = useMemo(() => {
    if (!playersData?.players) return new Map()
    return new Map(playersData.players.map(p => [p.id, p]))
  }, [playersData?.players])

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  const handleSearch = (id: number) => {
    setManagerId(id)
  }

  const isLoading = playersLoading || managerLoading || picksLoading
  const error = managerError || picksError

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-white mb-2">Team Analyzer</h2>
        <p className="text-gray-400 text-sm mb-4">
          Enter your FPL Manager ID to analyze your squad. You can find this in your FPL URL (e.g., fantasy.premierleague.com/entry/<strong>123456</strong>/event/1)
        </p>
        <ManagerSearch onSearch={handleSearch} isLoading={isLoading} />
      </div>

      {error && (
        <div className="p-4 bg-red-900/50 border border-red-500/30 rounded-lg text-red-400">
          {error.message || 'Failed to load manager data'}
        </div>
      )}

      {manager && (
        <div className="bg-gray-800 rounded-lg p-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-lg font-bold text-white">{manager.name}</h3>
              <p className="text-gray-400">
                {manager.player_first_name} {manager.player_last_name}
              </p>
            </div>
            <div className="text-right">
              <div className="text-2xl font-bold text-green-400">{manager.summary_overall_points}</div>
              <div className="text-sm text-gray-400">
                Rank: {manager.summary_overall_rank?.toLocaleString() ?? 'N/A'}
              </div>
            </div>
          </div>
        </div>
      )}

      {picks && picks.picks && (
        <>
          <SquadStats
            picks={picks.picks}
            players={playersMap}
            entryHistory={picks.entry_history}
          />

          <SquadPitch
            picks={picks.picks}
            players={playersMap}
            teams={teamsMap}
          />

          {picks.active_chip && (
            <div className="p-3 bg-purple-900/50 border border-purple-500/30 rounded-lg text-center">
              <span className="text-purple-300 font-medium">Active Chip: </span>
              <span className="text-purple-100 font-bold uppercase">{picks.active_chip}</span>
            </div>
          )}
        </>
      )}

      {!manager && !isLoading && !error && (
        <div className="text-center py-12 text-gray-500">
          Enter a manager ID above to view their squad
        </div>
      )}

      {isLoading && (
        <div className="text-center py-12 text-gray-400">
          Loading squad data...
        </div>
      )}
    </div>
  )
}
