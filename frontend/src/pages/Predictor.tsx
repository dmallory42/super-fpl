import { useState, useMemo } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { usePredictions } from '../hooks/usePredictions'
import { PredictionTable } from '../components/predictor/PredictionTable'

export function Predictor() {
  const [gameweek, setGameweek] = useState<number>(1)
  const { data: playersData } = usePlayers()
  const { data: predictionsData, isLoading, error } = usePredictions(gameweek)

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  // Estimate current gameweek (rough approximation)
  // In production, this would come from the API
  const estimatedCurrentGw = useMemo(() => {
    const seasonStart = new Date('2025-08-16') // Approximate EPL start
    const now = new Date()
    const weeks = Math.floor((now.getTime() - seasonStart.getTime()) / (7 * 24 * 60 * 60 * 1000))
    return Math.max(1, Math.min(38, weeks + 1))
  }, [])

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-white mb-2">Points Predictor</h2>
        <p className="text-gray-400 text-sm mb-4">
          Predicted points for each player based on form, fixtures, and historical data.
        </p>

        <div className="flex items-center gap-4">
          <label className="text-gray-300">Gameweek:</label>
          <select
            value={gameweek}
            onChange={(e) => setGameweek(parseInt(e.target.value, 10))}
            className="px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-green-500"
          >
            {Array.from({ length: 38 }, (_, i) => i + 1).map(gw => (
              <option key={gw} value={gw}>
                GW{gw} {gw === estimatedCurrentGw ? '(Current)' : ''}
              </option>
            ))}
          </select>
        </div>
      </div>

      {error && (
        <div className="p-4 bg-red-900/50 border border-red-500/30 rounded-lg text-red-400">
          {error.message || 'Failed to load predictions'}
        </div>
      )}

      {isLoading && (
        <div className="text-center py-12 text-gray-400">
          Generating predictions for GW{gameweek}...
        </div>
      )}

      {predictionsData && predictionsData.predictions.length > 0 && (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <StatCard
              label="Total Players"
              value={predictionsData.predictions.length.toString()}
            />
            <StatCard
              label="Top Predicted"
              value={predictionsData.predictions[0]?.web_name || '-'}
              subvalue={`${predictionsData.predictions[0]?.predicted_points.toFixed(1)} pts`}
            />
            <StatCard
              label="Avg Prediction"
              value={`${(predictionsData.predictions.reduce((s, p) => s + p.predicted_points, 0) / predictionsData.predictions.length).toFixed(1)} pts`}
            />
            <StatCard
              label="Generated"
              value={new Date(predictionsData.generated_at).toLocaleTimeString()}
            />
          </div>

          <PredictionTable
            predictions={predictionsData.predictions}
            teams={teamsMap}
          />
        </>
      )}

      {predictionsData && predictionsData.predictions.length === 0 && (
        <div className="text-center py-12 text-gray-500">
          No predictions available for GW{gameweek}. Try syncing player data first.
        </div>
      )}
    </div>
  )
}

interface StatCardProps {
  label: string
  value: string
  subvalue?: string
}

function StatCard({ label, value, subvalue }: StatCardProps) {
  return (
    <div className="p-4 rounded-lg bg-gray-800">
      <div className="text-sm text-gray-400">{label}</div>
      <div className="text-lg font-bold text-white">{value}</div>
      {subvalue && <div className="text-sm text-green-400">{subvalue}</div>}
    </div>
  )
}
