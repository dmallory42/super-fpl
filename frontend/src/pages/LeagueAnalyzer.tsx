import { useState, useMemo } from 'react'
import { useLeagueAnalysis } from '../hooks/useLeagueAnalysis'
import { usePlayers } from '../hooks/usePlayers'
import { RiskMeter } from '../components/comparator/RiskMeter'
import { OwnershipMatrix } from '../components/comparator/OwnershipMatrix'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { EmptyState, UsersIcon } from '../components/ui/EmptyState'
import { SkeletonCard, SkeletonTable } from '../components/ui/SkeletonLoader'
import { GradientText } from '../components/ui/GradientText'
import { getPositionName } from '../types'

export function LeagueAnalyzer() {
  const [leagueInput, setLeagueInput] = useState('')
  const [leagueId, setLeagueId] = useState<number | null>(null)
  const [gameweek, setGameweek] = useState<number | undefined>()

  const { data: analysisData, isLoading, error } = useLeagueAnalysis(leagueId, gameweek)
  const { data: playersData } = usePlayers()

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map<number, string>()
    return new Map(playersData.teams.map((t) => [t.id, t.short_name]))
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
    return analysisData?.managers.map((m) => m.id) || []
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
      {/* Header */}
      <div className="animate-fade-in-up">
        <h2 className="font-display text-2xl font-bold tracking-wider text-foreground mb-2">
          League Analyzer
        </h2>
        <p className="text-foreground-muted text-sm">
          Analyze your mini-league to find differentials, compare ownership, and assess risk.
        </p>
      </div>

      {/* League Search */}
      <form
        onSubmit={handleSubmit}
        className="flex gap-2 max-w-md animate-fade-in-up animation-delay-100"
      >
        <input
          type="text"
          value={leagueInput}
          onChange={(e) => setLeagueInput(e.target.value)}
          placeholder="Enter League ID"
          className="input-broadcast flex-1"
        />
        <button type="submit" className="btn-primary">
          Analyze
        </button>
      </form>

      {/* Gameweek Selector */}
      {leagueId && (
        <div className="flex items-center gap-4 animate-fade-in-up animation-delay-200">
          <label className="text-foreground-muted font-display text-sm uppercase tracking-wider">
            Gameweek:
          </label>
          <select
            value={gameweek || ''}
            onChange={(e) => setGameweek(e.target.value ? parseInt(e.target.value, 10) : undefined)}
            className="input-broadcast w-32"
          >
            <option value="">Current</option>
            {Array.from({ length: 38 }, (_, i) => i + 1).map((gw) => (
              <option key={gw} value={gw}>
                GW{gw}
              </option>
            ))}
          </select>
        </div>
      )}

      {/* Loading State */}
      {isLoading && (
        <div className="space-y-6">
          <SkeletonCard />
          <SkeletonTable rows={6} cols={6} />
        </div>
      )}

      {/* Error State */}
      {error && (
        <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
          {error.message || 'Failed to load league data'}
        </div>
      )}

      {/* Analysis Results */}
      {analysisData && (
        <div className="space-y-6">
          {/* League Header Banner */}
          <BroadcastCard animationDelay={0}>
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              <div>
                <h3 className="font-display text-2xl font-bold tracking-wider">
                  <GradientText>{analysisData.league.name}</GradientText>
                </h3>
                <p className="text-foreground-muted text-sm">
                  Analyzing top {analysisData.managers.length} managers
                </p>
              </div>
              <div className="text-right">
                <div className="font-display text-xl font-bold text-foreground tracking-wider">
                  GW{analysisData.gameweek}
                </div>
              </div>
            </div>
          </BroadcastCard>

          {/* Standings with Risk */}
          <BroadcastCard title="League Standings" animationDelay={100}>
            <div className="overflow-x-auto -mx-4">
              <table className="table-broadcast min-w-[700px]">
                <thead>
                  <tr>
                    <th>Rank</th>
                    <th>Manager</th>
                    <th>Team</th>
                    <th className="text-right">Points</th>
                    <th className="text-center">Risk</th>
                    <th>Key Differentials</th>
                  </tr>
                </thead>
                <tbody>
                  {analysisData.managers.map((manager, idx) => {
                    const risk = analysisData.comparison.risk_scores[manager.id]
                    const diffs = analysisData.comparison.differentials[manager.id] || []

                    return (
                      <tr
                        key={manager.id}
                        className="animate-fade-in-up opacity-0"
                        style={{ animationDelay: `${150 + idx * 50}ms` }}
                      >
                        <td className="font-mono text-foreground-muted">{manager.rank}</td>
                        <td className="font-medium text-foreground">{manager.name}</td>
                        <td className="text-foreground-muted">{manager.team_name}</td>
                        <td className="text-right font-mono font-bold text-fpl-green">
                          {manager.total}
                        </td>
                        <td>
                          {risk && (
                            <div className="flex justify-center">
                              <RiskMeter riskScore={risk} compact />
                            </div>
                          )}
                        </td>
                        <td>
                          <div className="flex gap-1 flex-wrap">
                            {diffs.slice(0, 3).map((d) => {
                              const player = analysisData.comparison.players[d.player_id]
                              return (
                                <span
                                  key={d.player_id}
                                  className="px-2 py-0.5 rounded text-xs font-medium border bg-fpl-green/20 text-fpl-green border-fpl-green/30"
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
          </BroadcastCard>

          {/* Risk Analysis Grid */}
          <BroadcastCard title="Risk Analysis" accentColor="highlight" animationDelay={200}>
            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
              {analysisData.managers.slice(0, 10).map((manager, idx) => (
                <div key={manager.id} style={{ animationDelay: `${250 + idx * 50}ms` }}>
                  <RiskMeter
                    managerName={manager.team_name}
                    riskScore={analysisData.comparison.risk_scores[manager.id]}
                  />
                </div>
              ))}
            </div>
          </BroadcastCard>

          {/* Differentials Detail */}
          <BroadcastCard title="Key Differentials" accentColor="purple" animationDelay={300}>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
              {analysisData.managers.slice(0, 6).map((manager, idx) => {
                const diffs = analysisData.comparison.differentials[manager.id] || []
                return (
                  <div
                    key={manager.id}
                    className="p-4 bg-surface-elevated rounded-lg animate-fade-in-up opacity-0"
                    style={{ animationDelay: `${350 + idx * 50}ms` }}
                  >
                    <div className="font-display text-sm uppercase tracking-wider text-foreground mb-3 truncate">
                      {manager.team_name}
                    </div>
                    {diffs.length === 0 ? (
                      <p className="text-sm text-foreground-dim">No major differentials</p>
                    ) : (
                      <ul className="space-y-2">
                        {diffs.slice(0, 5).map((diff) => {
                          const player = analysisData.comparison.players[diff.player_id]
                          return (
                            <li key={diff.player_id} className="flex items-center gap-2 text-sm">
                              <span className="font-mono text-xs w-8 text-foreground-muted">
                                {player ? getPositionName(player.position) : ''}
                              </span>
                              <span className="text-foreground flex-1 truncate">
                                {player?.web_name || diff.player_id}
                              </span>
                              <span className="text-fpl-green text-xs font-mono">
                                {diff.eo.toFixed(0)}%
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
          </BroadcastCard>

          {/* Ownership Matrix */}
          <BroadcastCard title="Ownership Matrix" animationDelay={400}>
            <OwnershipMatrix
              effectiveOwnership={analysisData.comparison.effective_ownership}
              ownershipMatrix={analysisData.comparison.ownership_matrix}
              players={analysisData.comparison.players}
              managerIds={managerIds}
              managerNames={managerNames}
              teams={teamsMap}
            />
          </BroadcastCard>
        </div>
      )}

      {/* Empty State */}
      {!leagueId && !isLoading && (
        <EmptyState
          icon={<UsersIcon size={64} />}
          title="Enter Your League ID"
          description="Analyze your mini-league to find differentials, compare ownership, and assess risk against your rivals."
        />
      )}
    </div>
  )
}
