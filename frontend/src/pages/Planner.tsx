import { useState, useMemo } from 'react'
import { useTransferSuggestions, useTransferTargets } from '../hooks/useTransfers'
import { usePlayers } from '../hooks/usePlayers'
import { getPositionName } from '../types'

export function Planner() {
  const [managerId, setManagerId] = useState<number | null>(null)
  const [managerInput, setManagerInput] = useState('')
  const [gameweek, setGameweek] = useState<number | undefined>(undefined)
  const [transferCount, setTransferCount] = useState(1)
  const [positionFilter, setPositionFilter] = useState<number | undefined>(undefined)
  const [maxPriceFilter, setMaxPriceFilter] = useState<number | undefined>(undefined)

  const { data: playersData } = usePlayers()
  const { data: suggestions, isLoading: isLoadingSuggestions, error: suggestionsError } = useTransferSuggestions(
    managerId,
    gameweek,
    transferCount
  )
  const { data: targets, isLoading: isLoadingTargets } = useTransferTargets(gameweek, positionFilter, maxPriceFilter)

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  const handleLoadManager = () => {
    const id = parseInt(managerInput, 10)
    if (!isNaN(id) && id > 0) {
      setManagerId(id)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-white mb-2">Transfer Planner</h2>
        <p className="text-gray-400 text-sm mb-4">
          Get transfer suggestions based on predicted points and analyze potential targets.
        </p>
      </div>

      {/* Controls */}
      <div className="grid md:grid-cols-3 gap-4">
        <div className="space-y-2">
          <label className="text-sm text-gray-400">Manager ID</label>
          <div className="flex gap-2">
            <input
              type="text"
              value={managerInput}
              onChange={(e) => setManagerInput(e.target.value)}
              placeholder="Enter FPL ID"
              className="flex-1 px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-green-500"
              onKeyDown={(e) => e.key === 'Enter' && handleLoadManager()}
            />
            <button
              onClick={handleLoadManager}
              className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg"
            >
              Load
            </button>
          </div>
        </div>

        <div className="space-y-2">
          <label className="text-sm text-gray-400">Gameweek</label>
          <select
            value={gameweek || ''}
            onChange={(e) => setGameweek(e.target.value ? parseInt(e.target.value, 10) : undefined)}
            className="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-green-500"
          >
            <option value="">Current</option>
            {Array.from({ length: 38 }, (_, i) => i + 1).map(gw => (
              <option key={gw} value={gw}>GW{gw}</option>
            ))}
          </select>
        </div>

        <div className="space-y-2">
          <label className="text-sm text-gray-400">Transfers to Plan</label>
          <select
            value={transferCount}
            onChange={(e) => setTransferCount(parseInt(e.target.value, 10))}
            className="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-green-500"
          >
            <option value={1}>1 Transfer</option>
            <option value={2}>2 Transfers</option>
            <option value={3}>3 Transfers</option>
          </select>
        </div>
      </div>

      {suggestionsError && (
        <div className="p-4 bg-red-900/50 border border-red-500/30 rounded-lg text-red-400">
          {suggestionsError.message || 'Failed to load suggestions'}
        </div>
      )}

      {isLoadingSuggestions && managerId && (
        <div className="text-center py-12 text-gray-400">
          Analyzing squad and generating suggestions...
        </div>
      )}

      {/* Transfer Suggestions */}
      {suggestions && !suggestions.error && (
        <div className="space-y-6">
          {/* Summary */}
          <div className="grid grid-cols-3 gap-4">
            <div className="p-4 bg-gray-800 rounded-lg text-center">
              <div className="text-2xl font-bold text-green-400">£{(suggestions.bank / 10).toFixed(1)}m</div>
              <div className="text-sm text-gray-400">In The Bank</div>
            </div>
            <div className="p-4 bg-gray-800 rounded-lg text-center">
              <div className="text-2xl font-bold text-blue-400">£{(suggestions.squad_value / 10).toFixed(1)}m</div>
              <div className="text-sm text-gray-400">Squad Value</div>
            </div>
            <div className="p-4 bg-gray-800 rounded-lg text-center">
              <div className="text-2xl font-bold text-yellow-400">
                {suggestions.squad_analysis.total_predicted_points.toFixed(1)}
              </div>
              <div className="text-sm text-gray-400">Predicted Points</div>
            </div>
          </div>

          {/* Suggested Transfers */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Suggested Transfers</h3>
            {suggestions.suggestions.length === 0 ? (
              <div className="p-4 bg-gray-800 rounded-lg text-gray-500 text-center">
                No transfer suggestions available. Your squad looks optimized!
              </div>
            ) : (
              <div className="space-y-4">
                {suggestions.suggestions.map((suggestion, idx) => (
                  <div key={idx} className="p-4 bg-gray-800 rounded-lg">
                    <div className="flex flex-col lg:flex-row lg:items-start gap-4">
                      {/* Transfer Out */}
                      <div className="flex-1">
                        <div className="text-sm text-red-400 font-medium mb-2">Transfer Out</div>
                        <div className="p-3 bg-red-900/20 border border-red-500/30 rounded-lg">
                          <div className="flex items-center justify-between">
                            <div>
                              <div className="text-white font-medium">{suggestion.out.web_name}</div>
                              <div className="text-xs text-gray-400">
                                {teamsMap.get(suggestion.out.team)} · {getPositionName(suggestion.out.position)}
                              </div>
                            </div>
                            <div className="text-right">
                              <div className="text-gray-300">£{(suggestion.out.selling_price / 10).toFixed(1)}m</div>
                              <div className="text-xs text-gray-500">
                                {suggestion.out.predicted_points.toFixed(1)} pts
                              </div>
                            </div>
                          </div>
                          <div className="mt-2 text-xs text-red-300">
                            {suggestion.out.reason}
                          </div>
                        </div>
                      </div>

                      {/* Arrow */}
                      <div className="hidden lg:flex items-center justify-center px-4">
                        <div className="text-2xl text-gray-500">→</div>
                      </div>

                      {/* Transfer In Options */}
                      <div className="flex-[2]">
                        <div className="text-sm text-green-400 font-medium mb-2">Best Replacements</div>
                        <div className="space-y-2">
                          {suggestion.in.slice(0, 3).map((player) => (
                            <div
                              key={player.player_id}
                              className="p-3 bg-green-900/20 border border-green-500/30 rounded-lg flex items-center justify-between"
                            >
                              <div>
                                <div className="text-white font-medium">{player.web_name}</div>
                                <div className="text-xs text-gray-400">
                                  {teamsMap.get(player.team)} · Form: {player.form.toFixed(1)}
                                </div>
                              </div>
                              <div className="text-right">
                                <div className="text-green-400 font-bold">
                                  {player.predicted_points.toFixed(1)} pts
                                </div>
                                <div className="text-xs text-gray-400">
                                  £{player.now_cost.toFixed(1)}m
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Weakest Players */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Squad Analysis</h3>
            <div className="grid md:grid-cols-3 gap-3">
              {suggestions.squad_analysis.weakest_players.map((player) => (
                <div key={player.player_id} className="p-3 bg-gray-800 rounded-lg">
                  <div className="text-white font-medium">{player.web_name}</div>
                  <div className="flex justify-between text-sm mt-1">
                    <span className="text-gray-400">Predicted: {player.predicted_points.toFixed(1)}</span>
                    <span className="text-gray-400">Form: {player.form.toFixed(1)}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Top Transfer Targets */}
      <div>
        <h3 className="text-lg font-semibold text-white mb-3">Top Transfer Targets</h3>

        {/* Filters */}
        <div className="flex gap-4 mb-4">
          <select
            value={positionFilter || ''}
            onChange={(e) => setPositionFilter(e.target.value ? parseInt(e.target.value, 10) : undefined)}
            className="px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm focus:outline-none focus:border-green-500"
          >
            <option value="">All Positions</option>
            <option value={1}>Goalkeepers</option>
            <option value={2}>Defenders</option>
            <option value={3}>Midfielders</option>
            <option value={4}>Forwards</option>
          </select>

          <select
            value={maxPriceFilter || ''}
            onChange={(e) => setMaxPriceFilter(e.target.value ? parseFloat(e.target.value) : undefined)}
            className="px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm focus:outline-none focus:border-green-500"
          >
            <option value="">Any Price</option>
            <option value={5}>Under £5.0m</option>
            <option value={6}>Under £6.0m</option>
            <option value={7}>Under £7.0m</option>
            <option value={8}>Under £8.0m</option>
            <option value={10}>Under £10.0m</option>
          </select>
        </div>

        {isLoadingTargets ? (
          <div className="text-center py-8 text-gray-400">Loading targets...</div>
        ) : targets?.targets?.length ? (
          <div className="bg-gray-800 rounded-lg overflow-hidden">
            <table className="w-full">
              <thead>
                <tr className="bg-gray-700/50 text-left">
                  <th className="px-4 py-3 text-gray-300 font-medium">Player</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">Pos</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">Price</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">Form</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">Predicted</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">Value</th>
                </tr>
              </thead>
              <tbody>
                {targets.targets.map((target) => (
                  <tr key={target.player_id} className="border-t border-gray-700 hover:bg-gray-700/30">
                    <td className="px-4 py-3">
                      <div className="text-white">{target.web_name}</div>
                      <div className="text-xs text-gray-500">{teamsMap.get(target.team)}</div>
                    </td>
                    <td className="px-4 py-3 text-center text-gray-400">
                      {getPositionName(target.position)}
                    </td>
                    <td className="px-4 py-3 text-center text-gray-300">
                      £{(target.now_cost / 10).toFixed(1)}m
                    </td>
                    <td className="px-4 py-3 text-center text-gray-300">
                      {target.form.toFixed(1)}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className="text-green-400 font-bold">
                        {target.predicted_points.toFixed(1)}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center text-blue-400">
                      {target.value_score.toFixed(2)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="text-gray-500 text-center py-8 bg-gray-800 rounded-lg">
            No transfer targets available
          </div>
        )}
      </div>

      {!managerId && (
        <div className="text-center py-8 text-gray-500">
          Enter your FPL Manager ID to get personalized transfer suggestions
        </div>
      )}
    </div>
  )
}
