import { useState, useMemo } from 'react'
import { useLiveManager, useLiveBonus } from '../hooks/useLive'
import { usePlayers } from '../hooks/usePlayers'
import { getPositionName } from '../types'

export function Live() {
  const [managerId, setManagerId] = useState<number | null>(null)
  const [managerInput, setManagerInput] = useState('')
  const [gameweek, setGameweek] = useState<number>(1)

  const { data: playersData } = usePlayers()
  const { data: liveManager, isLoading: isLoadingManager, error: managerError } = useLiveManager(gameweek, managerId)
  const { data: bonusData, isLoading: isLoadingBonus } = useLiveBonus(gameweek)

  const playersMap = useMemo(() => {
    if (!playersData?.players) return new Map()
    return new Map(playersData.players.map(p => [p.id, p]))
  }, [playersData?.players])

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

  const startingXI = liveManager?.players?.filter(p => p.is_playing) ?? []
  const bench = liveManager?.players?.filter(p => !p.is_playing) ?? []

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-white mb-2">Live Gameweek Tracker</h2>
        <p className="text-gray-400 text-sm mb-4">
          Track live points for your team during the gameweek.
        </p>
      </div>

      {/* Controls */}
      <div className="grid md:grid-cols-2 gap-4">
        <div className="space-y-2">
          <label className="text-sm text-gray-400">Manager ID</label>
          <div className="flex gap-2">
            <input
              type="text"
              value={managerInput}
              onChange={(e) => setManagerInput(e.target.value)}
              placeholder="Enter your FPL ID"
              className="flex-1 px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-green-500"
              onKeyDown={(e) => e.key === 'Enter' && handleLoadManager()}
            />
            <button
              onClick={handleLoadManager}
              className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg"
            >
              Track
            </button>
          </div>
        </div>

        <div className="space-y-2">
          <label className="text-sm text-gray-400">Gameweek</label>
          <select
            value={gameweek}
            onChange={(e) => setGameweek(parseInt(e.target.value, 10))}
            className="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-green-500"
          >
            {Array.from({ length: 38 }, (_, i) => i + 1).map(gw => (
              <option key={gw} value={gw}>Gameweek {gw}</option>
            ))}
          </select>
        </div>
      </div>

      {managerError && (
        <div className="p-4 bg-red-900/50 border border-red-500/30 rounded-lg text-red-400">
          {managerError.message || 'Failed to load live data'}
        </div>
      )}

      {isLoadingManager && managerId && (
        <div className="text-center py-12 text-gray-400">
          Loading live points...
        </div>
      )}

      {/* Live Points Display */}
      {liveManager && !liveManager.error && (
        <div className="space-y-6">
          {/* Summary */}
          <div className="grid grid-cols-3 gap-4">
            <div className="p-4 bg-gray-800 rounded-lg text-center">
              <div className="text-3xl font-bold text-green-400">{liveManager.total_points}</div>
              <div className="text-sm text-gray-400">Live Points</div>
            </div>
            <div className="p-4 bg-gray-800 rounded-lg text-center">
              <div className="text-3xl font-bold text-blue-400">{liveManager.bench_points}</div>
              <div className="text-sm text-gray-400">Bench Points</div>
            </div>
            <div className="p-4 bg-gray-800 rounded-lg text-center">
              <div className="text-sm text-gray-400">Last Updated</div>
              <div className="text-sm text-white">
                {new Date(liveManager.updated_at).toLocaleTimeString()}
              </div>
            </div>
          </div>

          {/* Starting XI */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Starting XI</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
              {startingXI.map(pick => {
                const player = playersMap.get(pick.player_id)
                const teamName = player ? teamsMap.get(player.team) : ''
                return (
                  <div
                    key={pick.player_id}
                    className={`p-3 rounded-lg flex items-center justify-between ${
                      pick.is_captain ? 'bg-yellow-900/30 border border-yellow-600/30' : 'bg-gray-800'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full bg-gray-700 flex items-center justify-center text-xs font-bold">
                        {pick.position}
                      </div>
                      <div>
                        <div className="text-white font-medium flex items-center gap-1">
                          {player?.web_name || `Player ${pick.player_id}`}
                          {pick.is_captain && (
                            <span className="text-yellow-400 font-bold text-xs">(C)</span>
                          )}
                          {pick.multiplier === 3 && (
                            <span className="text-purple-400 font-bold text-xs">(TC)</span>
                          )}
                        </div>
                        <div className="text-xs text-gray-400">
                          {teamName} · {player ? getPositionName(player.position) : ''}
                        </div>
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-xl font-bold text-green-400">
                        {pick.effective_points}
                      </div>
                      {pick.multiplier > 1 && (
                        <div className="text-xs text-gray-500">
                          ({pick.points} × {pick.multiplier})
                        </div>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          {/* Bench */}
          <div>
            <h3 className="text-lg font-semibold text-white mb-3">Bench</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
              {bench.map(pick => {
                const player = playersMap.get(pick.player_id)
                const teamName = player ? teamsMap.get(player.team) : ''
                return (
                  <div key={pick.player_id} className="p-3 bg-gray-800/50 rounded-lg flex items-center justify-between">
                    <div>
                      <div className="text-gray-300 font-medium">
                        {player?.web_name || `Player ${pick.player_id}`}
                      </div>
                      <div className="text-xs text-gray-500">
                        {teamName} · {player ? getPositionName(player.position) : ''}
                      </div>
                    </div>
                    <div className="text-lg font-bold text-gray-400">
                      {pick.points}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      )}

      {/* Bonus Predictions */}
      <div>
        <h3 className="text-lg font-semibold text-white mb-3">Bonus Point Predictions</h3>
        {isLoadingBonus ? (
          <div className="text-gray-400 text-center py-8">Loading bonus predictions...</div>
        ) : bonusData?.bonus_predictions?.length ? (
          <div className="bg-gray-800 rounded-lg overflow-hidden">
            <table className="w-full">
              <thead>
                <tr className="bg-gray-700/50 text-left">
                  <th className="px-4 py-3 text-gray-300 font-medium">Player</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">BPS</th>
                  <th className="px-4 py-3 text-gray-300 font-medium text-center">Predicted Bonus</th>
                </tr>
              </thead>
              <tbody>
                {bonusData.bonus_predictions.slice(0, 20).map((bp) => {
                  const player = playersMap.get(bp.player_id)
                  const teamName = player ? teamsMap.get(player.team) : ''
                  return (
                    <tr key={`${bp.player_id}-${bp.fixture_id}`} className="border-t border-gray-700">
                      <td className="px-4 py-3">
                        <div className="text-white">{player?.web_name || `Player ${bp.player_id}`}</div>
                        <div className="text-xs text-gray-500">{teamName}</div>
                      </td>
                      <td className="px-4 py-3 text-center text-gray-300">{bp.bps}</td>
                      <td className="px-4 py-3 text-center">
                        <span className={`font-bold ${
                          bp.predicted_bonus === 3 ? 'text-yellow-400' :
                          bp.predicted_bonus === 2 ? 'text-gray-300' :
                          'text-orange-400'
                        }`}>
                          +{bp.predicted_bonus}
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="text-gray-500 text-center py-8 bg-gray-800 rounded-lg">
            No bonus predictions available for this gameweek
          </div>
        )}
      </div>

      {!managerId && (
        <div className="text-center py-8 text-gray-500">
          Enter your FPL Manager ID to track your live gameweek points
        </div>
      )}
    </div>
  )
}
