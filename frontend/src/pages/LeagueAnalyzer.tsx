import { useState, useMemo } from 'react'
import { useLeagueAnalysis } from '../hooks/useLeagueAnalysis'
import { usePlayers } from '../hooks/usePlayers'
import { RiskMeter } from '../components/comparator/RiskMeter'
import { OwnershipMatrix } from '../components/comparator/OwnershipMatrix'
import { getPositionName } from '../types'

export function LeagueAnalyzer() {
  const [leagueInput, setLeagueInput] = useState('')
  const [leagueId, setLeagueId] = useState<number | null>(null)
  const [gameweek, setGameweek] = useState<number | undefined>()

  const { data: analysisData, isLoading, error } = useLeagueAnalysis(leagueId, gameweek)
  const { data: playersData } = usePlayers()

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map<number, string>()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  const managerNames = useMemo(() => {
    const names: Record<number, string> = {}
    if (analysisData?.managers) {
      for (const m of analysisData.managers) {
        names[m.id] = m.team_name || m.name
      }
    }
    return names
  }, [analysisData?.managers])

  const managerIds = useMemo(() => {
    return analysisData?.managers.map(m => m.id) || []
  }, [analysisData?.managers])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const id = parseInt(leagueInput, 10)
    if (id > 0) {
      setLeagueId(id)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-white mb-2">League Analyzer</h2>
        <p className="text-gray-400 text-sm">
          Analyze your mini-league to find differentials, compare ownership, and assess risk.
        </p>
      </div>

      {/* League search */}
      <form onSubmit={handleSubmit} className="flex gap-2 max-w-md">
        <input
          type="text"
          value={leagueInput}
          onChange={(e) => setLeagueInput(e.target.value)}
          placeholder="Enter League ID"
          className="flex-1 px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-green-500"
        />
        <button
          type="submit"
          className="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium"
        >
          Analyze
        </button>
      </form>

      {/* Gameweek selector */}
      {leagueId && (
        <div className="flex items-center gap-4">
          <label className="text-gray-300">Gameweek:</label>
          <select
            value={gameweek || ''}
            onChange={(e) => setGameweek(e.target.value ? parseInt(e.target.value, 10) : undefined)}
            className="px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-green-500"
          >
            <option value="">Current</option>
            {Array.from({ length: 38 }, (_, i) => i + 1).map(gw => (
              <option key={gw} value={gw}>GW{gw}</option>
            ))}
          </select>
        </div>
      )}

      {isLoading && (
        <div className="text-center py-12 text-gray-400">
          Loading league analysis...
        </div>
      )}

      {error && (
        <div className="p-4 bg-red-900/50 border border-red-500/30 rounded-lg text-red-400">
          {error.message || 'Failed to load league data'}
        </div>
      )}

      {analysisData && (
        <div className="space-y-6">
          {/* League header */}
          <div className="bg-gray-800 p-4 rounded-lg">
            <h3 className="text-xl font-bold text-white">{analysisData.league.name}</h3>
            <p className="text-gray-400">
              Analyzing top {analysisData.managers.length} managers | GW{analysisData.gameweek}
            </p>
          </div>

          {/* Standings with risk */}
          <div className="bg-gray-800 rounded-lg overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-white">
                <thead className="bg-gray-700">
                  <tr>
                    <th className="px-4 py-3 text-left text-sm font-medium">Rank</th>
                    <th className="px-4 py-3 text-left text-sm font-medium">Manager</th>
                    <th className="px-4 py-3 text-left text-sm font-medium">Team</th>
                    <th className="px-4 py-3 text-right text-sm font-medium">Points</th>
                    <th className="px-4 py-3 text-center text-sm font-medium">Risk</th>
                    <th className="px-4 py-3 text-left text-sm font-medium">Key Differentials</th>
                  </tr>
                </thead>
                <tbody>
                  {analysisData.managers.map((manager) => {
                    const risk = analysisData.comparison.risk_scores[manager.id]
                    const diffs = analysisData.comparison.differentials[manager.id] || []

                    return (
                      <tr key={manager.id} className="border-t border-gray-700 hover:bg-gray-700/50">
                        <td className="px-4 py-3 text-gray-400">{manager.rank}</td>
                        <td className="px-4 py-3 font-medium">{manager.name}</td>
                        <td className="px-4 py-3 text-gray-400">{manager.team_name}</td>
                        <td className="px-4 py-3 text-right font-bold">{manager.total}</td>
                        <td className="px-4 py-3">
                          {risk && (
                            <div className="flex justify-center">
                              <RiskMeter riskScore={risk} compact />
                            </div>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex gap-1 flex-wrap">
                            {diffs.slice(0, 3).map((d) => {
                              const player = analysisData.comparison.players[d.player_id]
                              return (
                                <span
                                  key={d.player_id}
                                  className="bg-green-600/30 text-green-400 px-2 py-0.5 rounded text-xs"
                                >
                                  {player?.web_name || d.player_id}
                                </span>
                              )
                            })}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {/* Risk Analysis Grid */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Risk Analysis</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
              {analysisData.managers.slice(0, 10).map(manager => (
                <RiskMeter
                  key={manager.id}
                  managerName={manager.team_name}
                  riskScore={analysisData.comparison.risk_scores[manager.id]}
                />
              ))}
            </div>
          </div>

          {/* Differentials Detail */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Key Differentials</h3>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
              {analysisData.managers.slice(0, 6).map(manager => {
                const diffs = analysisData.comparison.differentials[manager.id] || []
                return (
                  <div key={manager.id} className="p-4 bg-gray-800 rounded-lg">
                    <div className="font-medium text-white mb-2 truncate">
                      {manager.team_name}
                    </div>
                    {diffs.length === 0 ? (
                      <p className="text-sm text-gray-500">No major differentials</p>
                    ) : (
                      <ul className="space-y-1">
                        {diffs.slice(0, 5).map(diff => {
                          const player = analysisData.comparison.players[diff.player_id]
                          return (
                            <li key={diff.player_id} className="flex items-center gap-2 text-sm">
                              <span className="text-gray-400 w-8">
                                {player ? getPositionName(player.position) : ''}
                              </span>
                              <span className="text-white flex-1 truncate">
                                {player?.web_name || diff.player_id}
                              </span>
                              <span className="text-green-400 text-xs">
                                {diff.eo.toFixed(0)}% EO
                              </span>
                              {diff.is_captain && (
                                <span className="text-yellow-400 text-xs font-bold">C</span>
                              )}
                            </li>
                          )
                        })}
                      </ul>
                    )}
                  </div>
                )
              })}
            </div>
          </div>

          {/* Ownership Matrix */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Ownership Matrix</h3>
            <div className="bg-gray-800 rounded-lg p-4 overflow-hidden">
              <OwnershipMatrix
                effectiveOwnership={analysisData.comparison.effective_ownership}
                ownershipMatrix={analysisData.comparison.ownership_matrix}
                players={analysisData.comparison.players}
                managerIds={managerIds}
                managerNames={managerNames}
                teams={teamsMap}
              />
            </div>
          </div>
        </div>
      )}

      {!leagueId && !isLoading && (
        <div className="text-center py-12 text-gray-500">
          Enter a league ID to analyze your mini-league
        </div>
      )}
    </div>
  )
}
