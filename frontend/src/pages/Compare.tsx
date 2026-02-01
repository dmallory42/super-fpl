import { useState, useMemo, useCallback } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { useCompare } from '../hooks/useCompare'
import { useLeague } from '../hooks/useLeague'
import { OwnershipMatrix } from '../components/comparator/OwnershipMatrix'
import { RiskMeter } from '../components/comparator/RiskMeter'
import { getPositionName } from '../types'

export function Compare() {
  const [inputValue, setInputValue] = useState('')
  const [managerIds, setManagerIds] = useState<number[]>([])
  const [leagueId, setLeagueId] = useState<number | null>(null)
  const [gameweek, setGameweek] = useState<number | undefined>(undefined)

  const { data: playersData } = usePlayers()
  const { data: leagueData } = useLeague(leagueId)
  const { data: comparison, isLoading, error } = useCompare(managerIds, gameweek)

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  // Manager names from league data or IDs
  const managerNames = useMemo(() => {
    const names: Record<number, string> = {}

    if (leagueData?.standings?.results) {
      for (const result of leagueData.standings.results) {
        names[result.entry] = result.entry_name || result.player_name
      }
    }

    // Fallback to ID for any missing
    for (const id of managerIds) {
      if (!names[id]) {
        names[id] = `Manager ${id}`
      }
    }

    return names
  }, [leagueData, managerIds])

  const handleAddManagers = useCallback(() => {
    const ids = inputValue
      .split(/[,\s]+/)
      .map(s => parseInt(s.trim(), 10))
      .filter(n => !isNaN(n) && n > 0)

    if (ids.length > 0) {
      setManagerIds(prev => [...new Set([...prev, ...ids])])
      setInputValue('')
    }
  }, [inputValue])

  const handleLoadLeague = useCallback(() => {
    if (leagueData?.standings?.results) {
      const ids = leagueData.standings.results.slice(0, 20).map(r => r.entry)
      setManagerIds(ids)
    }
  }, [leagueData])

  const handleRemoveManager = useCallback((id: number) => {
    setManagerIds(prev => prev.filter(m => m !== id))
  }, [])

  const handleClear = useCallback(() => {
    setManagerIds([])
    setLeagueId(null)
  }, [])

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-white mb-2">Manager Comparison</h2>
        <p className="text-gray-400 text-sm mb-4">
          Compare multiple managers to find differentials and analyze effective ownership.
        </p>
      </div>

      {/* Input Controls */}
      <div className="grid md:grid-cols-2 gap-4">
        <div className="space-y-2">
          <label className="text-sm text-gray-400">Add Manager IDs</label>
          <div className="flex gap-2">
            <input
              type="text"
              value={inputValue}
              onChange={(e) => setInputValue(e.target.value)}
              placeholder="e.g., 123456, 789012"
              className="flex-1 px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-green-500"
              onKeyDown={(e) => e.key === 'Enter' && handleAddManagers()}
            />
            <button
              onClick={handleAddManagers}
              className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg"
            >
              Add
            </button>
          </div>
        </div>

        <div className="space-y-2">
          <label className="text-sm text-gray-400">Or Load from League</label>
          <div className="flex gap-2">
            <input
              type="text"
              value={leagueId || ''}
              onChange={(e) => setLeagueId(e.target.value ? parseInt(e.target.value, 10) : null)}
              placeholder="League ID"
              className="flex-1 px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-green-500"
            />
            <button
              onClick={handleLoadLeague}
              disabled={!leagueData}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 text-white rounded-lg"
            >
              Load Top 20
            </button>
          </div>
          {leagueData && (
            <p className="text-xs text-gray-500">
              {leagueData.league.name} - {leagueData.standings.results.length} members
            </p>
          )}
        </div>
      </div>

      {/* Selected Managers */}
      {managerIds.length > 0 && (
        <div className="flex flex-wrap gap-2 items-center">
          <span className="text-gray-400 text-sm">Comparing:</span>
          {managerIds.map(id => (
            <span
              key={id}
              className="px-3 py-1 bg-gray-800 rounded-full text-sm text-white flex items-center gap-2"
            >
              {managerNames[id] || id}
              <button
                onClick={() => handleRemoveManager(id)}
                className="text-gray-500 hover:text-red-400"
              >
                ×
              </button>
            </span>
          ))}
          <button
            onClick={handleClear}
            className="text-sm text-gray-500 hover:text-red-400"
          >
            Clear all
          </button>
        </div>
      )}

      {/* Gameweek Selector */}
      {managerIds.length >= 2 && (
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

      {error && (
        <div className="p-4 bg-red-900/50 border border-red-500/30 rounded-lg text-red-400">
          {error.message || 'Failed to load comparison data'}
        </div>
      )}

      {isLoading && managerIds.length >= 2 && (
        <div className="text-center py-12 text-gray-400">
          Loading comparison data...
        </div>
      )}

      {comparison && (
        <>
          {/* Risk Scores */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Risk Analysis</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              {managerIds.map(id => (
                <RiskMeter
                  key={id}
                  managerName={managerNames[id] || `Manager ${id}`}
                  riskScore={comparison.risk_scores[id]}
                />
              ))}
            </div>
          </div>

          {/* Differentials */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Key Differentials</h3>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
              {managerIds.map(id => {
                const diffs = comparison.differentials[id] || []
                return (
                  <div key={id} className="p-4 bg-gray-800 rounded-lg">
                    <div className="font-medium text-white mb-2 truncate">
                      {managerNames[id]}
                    </div>
                    {diffs.length === 0 ? (
                      <p className="text-sm text-gray-500">No major differentials</p>
                    ) : (
                      <ul className="space-y-1">
                        {diffs.slice(0, 5).map(diff => {
                          const player = comparison.players[diff.player_id]
                          return (
                            <li key={diff.player_id} className="flex items-center gap-2 text-sm">
                              <span className="text-gray-400">
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
                effectiveOwnership={comparison.effective_ownership}
                ownershipMatrix={comparison.ownership_matrix}
                players={comparison.players}
                managerIds={managerIds}
                managerNames={managerNames}
                teams={teamsMap}
              />
            </div>
          </div>
        </>
      )}

      {managerIds.length < 2 && !isLoading && (
        <div className="text-center py-12 text-gray-500">
          Add at least 2 manager IDs to compare their teams
        </div>
      )}
    </div>
  )
}
