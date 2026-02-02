import { useState, useMemo } from 'react'
import { useTransferSuggestions, useTransferTargets } from '../hooks/useTransfers'
import { usePlayers } from '../hooks/usePlayers'
import { usePlannerOptimize } from '../hooks/usePlannerOptimize'
import { getPositionName } from '../types'
import type { ChipPlan } from '../api/client'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { TabNav } from '../components/ui/TabNav'
import { EmptyState, ChartIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonCard, SkeletonTable } from '../components/ui/SkeletonLoader'

type ViewMode = 'optimize' | 'suggestions' | 'targets'

const viewTabs = [
  { id: 'optimize', label: 'Optimizer' },
  { id: 'suggestions', label: 'Quick' },
  { id: 'targets', label: 'Targets' },
]

const chipLabels: Record<string, string> = {
  wildcard: 'Wildcard',
  bench_boost: 'Bench Boost',
  free_hit: 'Free Hit',
  triple_captain: 'Triple Captain',
}

export function Planner() {
  const [managerId, setManagerId] = useState<number | null>(null)
  const [managerInput, setManagerInput] = useState('')
  const [gameweek, setGameweek] = useState<number | undefined>(undefined)
  const [transferCount, setTransferCount] = useState(1)
  const [freeTransfers, setFreeTransfers] = useState(1)
  const [positionFilter, setPositionFilter] = useState<number | undefined>(undefined)
  const [maxPriceFilter, setMaxPriceFilter] = useState<number | undefined>(undefined)
  const [viewMode, setViewMode] = useState<ViewMode>('optimize')
  const [chipPlan, setChipPlan] = useState<ChipPlan>({})

  const { data: playersData } = usePlayers()
  const { data: suggestions, isLoading: isLoadingSuggestions, error: suggestionsError } = useTransferSuggestions(
    managerId,
    gameweek,
    transferCount
  )
  const { data: targets, isLoading: isLoadingTargets } = useTransferTargets(gameweek, positionFilter, maxPriceFilter)
  const { data: optimizeData, isLoading: isLoadingOptimize, error: optimizeError } = usePlannerOptimize(
    managerId,
    freeTransfers,
    chipPlan
  )

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map<number, string>()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  const handleLoadManager = () => {
    const id = parseInt(managerInput, 10)
    if (!isNaN(id) && id > 0) {
      setManagerId(id)
    }
  }

  const setChipWeek = (chip: keyof ChipPlan, gw: number | undefined) => {
    setChipPlan(prev => {
      const next = { ...prev }
      if (gw === undefined) {
        delete next[chip]
      } else {
        next[chip] = gw
      }
      return next
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="animate-fade-in-up">
        <h2 className="font-display text-2xl font-bold tracking-wider text-foreground mb-2">
          Transfer Planner
        </h2>
        <p className="text-foreground-muted text-sm mb-4">
          Get multi-week transfer optimization, chip timing suggestions, and transfer recommendations.
        </p>
      </div>

      {/* Controls */}
      <div className="grid md:grid-cols-4 gap-4 animate-fade-in-up animation-delay-100">
        <div className="space-y-2">
          <label className="font-display text-xs uppercase tracking-wider text-foreground-muted">
            Manager ID
          </label>
          <div className="flex gap-2">
            <input
              type="text"
              value={managerInput}
              onChange={(e) => setManagerInput(e.target.value)}
              placeholder="Enter FPL ID"
              className="input-broadcast flex-1"
              onKeyDown={(e) => e.key === 'Enter' && handleLoadManager()}
            />
            <button onClick={handleLoadManager} className="btn-primary">
              Load
            </button>
          </div>
        </div>

        <div className="space-y-2">
          <label className="font-display text-xs uppercase tracking-wider text-foreground-muted">
            Free Transfers
          </label>
          <select
            value={freeTransfers}
            onChange={(e) => setFreeTransfers(parseInt(e.target.value, 10))}
            className="input-broadcast"
          >
            {[1, 2, 3, 4, 5].map(n => (
              <option key={n} value={n}>{n} FT</option>
            ))}
          </select>
        </div>

        <div className="space-y-2 md:col-span-2">
          <label className="font-display text-xs uppercase tracking-wider text-foreground-muted">
            View
          </label>
          <TabNav
            tabs={viewTabs}
            activeTab={viewMode}
            onTabChange={(id) => setViewMode(id as ViewMode)}
          />
        </div>
      </div>

      {/* Multi-Week Optimizer View */}
      {viewMode === 'optimize' && managerId && (
        <>
          {optimizeError && (
            <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
              {optimizeError.message || 'Failed to load optimization data'}
            </div>
          )}

          {isLoadingOptimize && (
            <div className="space-y-6">
              <SkeletonStatGrid />
              <SkeletonCard lines={4} />
            </div>
          )}

          {optimizeData && (
            <div className="space-y-6">
              {/* Squad Summary */}
              <StatPanelGrid>
                <StatPanel
                  label="Squad Value"
                  value={`£${optimizeData.current_squad.squad_value.toFixed(1)}m`}
                  animationDelay={0}
                />
                <StatPanel
                  label="Bank"
                  value={`£${optimizeData.current_squad.bank.toFixed(1)}m`}
                  animationDelay={50}
                />
                <StatPanel
                  label="Free Transfers"
                  value={freeTransfers}
                  animationDelay={100}
                />
                <StatPanel
                  label={`Predicted (Next ${optimizeData.planning_horizon.length} GWs)`}
                  value={`${optimizeData.current_squad.predicted_points.total} pts`}
                  highlight
                  animationDelay={150}
                />
              </StatPanelGrid>

              {/* Predicted Points by Gameweek */}
              <BroadcastCard title="Predicted Points by Gameweek" animationDelay={200}>
                <div className="flex gap-2 flex-wrap">
                  {optimizeData.planning_horizon.map((gw, idx) => {
                    const pts = optimizeData.current_squad.predicted_points[gw] ?? 0
                    const hasDgw = (optimizeData.dgw_teams[gw] ?? []).length > 0
                    return (
                      <div
                        key={gw}
                        className={`
                          px-4 py-3 rounded-lg animate-fade-in-up opacity-0
                          ${hasDgw ? 'bg-fpl-green/20 border border-fpl-green/30' : 'bg-surface-elevated'}
                        `}
                        style={{ animationDelay: `${250 + idx * 50}ms` }}
                      >
                        <div className="text-xs text-foreground-muted font-display uppercase">
                          GW{gw}{hasDgw && ' (DGW)'}
                        </div>
                        <div className="text-xl font-mono font-bold text-foreground">{pts}</div>
                      </div>
                    )
                  })}
                </div>
              </BroadcastCard>

              {/* Chip Planning */}
              <BroadcastCard title="Chip Planning" accentColor="purple" animationDelay={300}>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  {(['wildcard', 'bench_boost', 'free_hit', 'triple_captain'] as const).map((chip, idx) => {
                    const suggestion = optimizeData.chip_suggestions[chip]
                    const fixedGw = chipPlan[chip]

                    return (
                      <div
                        key={chip}
                        className="space-y-2 animate-fade-in-up opacity-0"
                        style={{ animationDelay: `${350 + idx * 50}ms` }}
                      >
                        <label className="font-display text-xs uppercase tracking-wider text-foreground">
                          {chipLabels[chip]}
                        </label>
                        <select
                          value={fixedGw ?? ''}
                          onChange={(e) => setChipWeek(chip, e.target.value ? parseInt(e.target.value, 10) : undefined)}
                          className="input-broadcast"
                        >
                          <option value="">Not planned</option>
                          {optimizeData.planning_horizon.map(gw => (
                            <option key={gw} value={gw}>
                              GW{gw} {suggestion?.gameweek === gw ? '(Suggested)' : ''}
                            </option>
                          ))}
                        </select>
                        {suggestion && !fixedGw && (
                          <div className="text-xs text-fpl-green">
                            Suggested: GW{suggestion.gameweek} (+{suggestion.estimated_value.toFixed(1)} pts)
                            {suggestion.has_dgw && ' - DGW'}
                          </div>
                        )}
                      </div>
                    )
                  })}
                </div>
              </BroadcastCard>

              {/* Transfer Recommendations */}
              <BroadcastCard title="Recommended Transfers" animationDelay={400}>
                <div className="space-y-4">
                  {optimizeData.recommendations.map((rec, idx) => (
                    <div
                      key={idx}
                      className={`
                        p-4 rounded-lg border animate-fade-in-up opacity-0
                        ${rec.recommended
                          ? 'border-fpl-green/30 bg-fpl-green/5'
                          : 'border-border bg-surface-elevated'
                        }
                      `}
                      style={{ animationDelay: `${450 + idx * 50}ms` }}
                    >
                      <div className="flex flex-col md:flex-row items-center justify-between gap-4">
                        <div className="flex items-center gap-4 flex-1">
                          {/* Out player */}
                          <div className="text-center min-w-[100px]">
                            <div className="text-xs text-destructive font-display uppercase tracking-wider mb-1">Out</div>
                            <div className="text-foreground font-bold">{rec.out.web_name}</div>
                            <div className="text-foreground-muted text-sm">{teamsMap.get(rec.out.team)}</div>
                            <div className="text-foreground-dim text-xs font-mono">
                              £{rec.out.price.toFixed(1)}m · {rec.out.total_predicted} pts
                            </div>
                          </div>

                          <div className="text-2xl text-foreground-dim">\u2192</div>

                          {/* In player */}
                          <div className="text-center min-w-[100px]">
                            <div className="text-xs text-fpl-green font-display uppercase tracking-wider mb-1">In</div>
                            <div className="text-foreground font-bold">{rec.in.web_name}</div>
                            <div className="text-foreground-muted text-sm">{teamsMap.get(rec.in.team)}</div>
                            <div className="text-foreground-dim text-xs font-mono">
                              £{rec.in.price.toFixed(1)}m · {rec.in.total_predicted} pts
                            </div>
                          </div>
                        </div>

                        {/* Gain summary */}
                        <div className="text-right">
                          <div className={`text-2xl font-mono font-bold ${rec.net_gain > 0 ? 'text-fpl-green' : 'text-destructive'}`}>
                            {rec.net_gain > 0 ? '+' : ''}{rec.net_gain} pts
                          </div>
                          <div className="text-xs text-foreground-muted">
                            {rec.is_free ? 'Free transfer' : `${rec.hit_cost} pt hit`}
                          </div>
                          {rec.recommended && (
                            <div className="text-xs text-fpl-green mt-1 font-display uppercase tracking-wider">
                              \u2713 Recommended
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  ))}

                  {optimizeData.recommendations.length === 0 && (
                    <div className="text-foreground-muted text-center py-8">
                      No beneficial transfers found. Your squad is well-optimized!
                    </div>
                  )}
                </div>
              </BroadcastCard>
            </div>
          )}
        </>
      )}

      {/* Quick Suggestions View */}
      {viewMode === 'suggestions' && (
        <>
          <div className="flex gap-4 animate-fade-in-up animation-delay-200">
            <div className="space-y-2">
              <label className="font-display text-xs uppercase tracking-wider text-foreground-muted">
                Gameweek
              </label>
              <select
                value={gameweek || ''}
                onChange={(e) => setGameweek(e.target.value ? parseInt(e.target.value, 10) : undefined)}
                className="input-broadcast"
              >
                <option value="">Current</option>
                {Array.from({ length: 38 }, (_, i) => i + 1).map(gw => (
                  <option key={gw} value={gw}>GW{gw}</option>
                ))}
              </select>
            </div>
            <div className="space-y-2">
              <label className="font-display text-xs uppercase tracking-wider text-foreground-muted">
                Transfers
              </label>
              <select
                value={transferCount}
                onChange={(e) => setTransferCount(parseInt(e.target.value, 10))}
                className="input-broadcast"
              >
                <option value={1}>1 Transfer</option>
                <option value={2}>2 Transfers</option>
                <option value={3}>3 Transfers</option>
              </select>
            </div>
          </div>

          {suggestionsError && (
            <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
              {suggestionsError.message || 'Failed to load suggestions'}
            </div>
          )}

          {isLoadingSuggestions && managerId && (
            <div className="space-y-6">
              <SkeletonStatGrid />
              <SkeletonCard lines={4} />
            </div>
          )}

          {suggestions && !suggestions.error && (
            <div className="space-y-6">
              {/* Summary */}
              <StatPanelGrid className="grid-cols-3">
                <StatPanel
                  label="In The Bank"
                  value={`£${(suggestions.bank / 10).toFixed(1)}m`}
                  animationDelay={0}
                />
                <StatPanel
                  label="Squad Value"
                  value={`£${(suggestions.squad_value / 10).toFixed(1)}m`}
                  animationDelay={50}
                />
                <StatPanel
                  label="Predicted Points"
                  value={suggestions.squad_analysis.total_predicted_points.toFixed(1)}
                  highlight
                  animationDelay={100}
                />
              </StatPanelGrid>

              {/* Suggested Transfers */}
              <BroadcastCard title="Suggested Transfers" animationDelay={150}>
                {suggestions.suggestions.length === 0 ? (
                  <div className="py-8 text-center text-foreground-muted">
                    No transfer suggestions available. Your squad looks optimized!
                  </div>
                ) : (
                  <div className="space-y-4">
                    {suggestions.suggestions.map((suggestion, idx) => (
                      <div
                        key={idx}
                        className="p-4 bg-surface-elevated rounded-lg animate-fade-in-up opacity-0"
                        style={{ animationDelay: `${200 + idx * 50}ms` }}
                      >
                        <div className="flex flex-col lg:flex-row lg:items-start gap-4">
                          {/* Transfer Out */}
                          <div className="flex-1">
                            <div className="text-xs text-destructive font-display uppercase tracking-wider mb-2">
                              Transfer Out
                            </div>
                            <div className="p-3 bg-destructive/10 border border-destructive/30 rounded-lg">
                              <div className="flex items-center justify-between">
                                <div>
                                  <div className="text-foreground font-medium">{suggestion.out.web_name}</div>
                                  <div className="text-xs text-foreground-muted">
                                    {teamsMap.get(suggestion.out.team)} · {getPositionName(suggestion.out.position)}
                                  </div>
                                </div>
                                <div className="text-right">
                                  <div className="text-foreground-muted font-mono">
                                    £{(suggestion.out.selling_price / 10).toFixed(1)}m
                                  </div>
                                  <div className="text-xs text-foreground-dim font-mono">
                                    {suggestion.out.predicted_points.toFixed(1)} pts
                                  </div>
                                </div>
                              </div>
                              <div className="mt-2 text-xs text-destructive/80">
                                {suggestion.out.reason}
                              </div>
                            </div>
                          </div>

                          {/* Arrow */}
                          <div className="hidden lg:flex items-center justify-center px-4 pt-8">
                            <div className="text-2xl text-foreground-dim">\u2192</div>
                          </div>

                          {/* Transfer In Options */}
                          <div className="flex-[2]">
                            <div className="text-xs text-fpl-green font-display uppercase tracking-wider mb-2">
                              Best Replacements
                            </div>
                            <div className="space-y-2">
                              {suggestion.in.slice(0, 3).map((player) => (
                                <div
                                  key={player.player_id}
                                  className="p-3 bg-fpl-green/10 border border-fpl-green/30 rounded-lg flex items-center justify-between"
                                >
                                  <div>
                                    <div className="text-foreground font-medium">{player.web_name}</div>
                                    <div className="text-xs text-foreground-muted">
                                      {teamsMap.get(player.team)} · Form: {player.form.toFixed(1)}
                                    </div>
                                  </div>
                                  <div className="text-right">
                                    <div className="text-fpl-green font-mono font-bold">
                                      {player.predicted_points.toFixed(1)} pts
                                    </div>
                                    <div className="text-xs text-foreground-muted font-mono">
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
              </BroadcastCard>

              {/* Weakest Players */}
              <BroadcastCard title="Squad Analysis" animationDelay={250}>
                <div className="grid md:grid-cols-3 gap-3">
                  {suggestions.squad_analysis.weakest_players.map((player, idx) => (
                    <div
                      key={player.player_id}
                      className="p-3 bg-surface-elevated rounded-lg animate-fade-in-up opacity-0"
                      style={{ animationDelay: `${300 + idx * 50}ms` }}
                    >
                      <div className="text-foreground font-medium">{player.web_name}</div>
                      <div className="flex justify-between text-sm mt-1 font-mono">
                        <span className="text-foreground-muted">
                          Predicted: {player.predicted_points.toFixed(1)}
                        </span>
                        <span className="text-foreground-muted">
                          Form: {player.form.toFixed(1)}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </BroadcastCard>
            </div>
          )}

          {!managerId && (
            <EmptyState
              icon={<ChartIcon size={64} />}
              title="Enter Your Manager ID"
              description="Get personalized transfer suggestions based on your current squad."
            />
          )}
        </>
      )}

      {/* Transfer Targets View */}
      {viewMode === 'targets' && (
        <div className="space-y-4">
          {/* Filters */}
          <div className="flex gap-4 animate-fade-in-up animation-delay-200">
            <select
              value={positionFilter || ''}
              onChange={(e) => setPositionFilter(e.target.value ? parseInt(e.target.value, 10) : undefined)}
              className="input-broadcast w-auto"
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
              className="input-broadcast w-auto"
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
            <SkeletonTable rows={10} cols={6} />
          ) : targets?.targets?.length ? (
            <BroadcastCard title="Top Transfer Targets" animationDelay={0}>
              <div className="overflow-x-auto -mx-4">
                <table className="table-broadcast">
                  <thead>
                    <tr>
                      <th>Player</th>
                      <th className="text-center">Pos</th>
                      <th className="text-center">Price</th>
                      <th className="text-center">Form</th>
                      <th className="text-center">Predicted</th>
                      <th className="text-center">Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    {targets.targets.map((target, idx) => (
                      <tr
                        key={target.player_id}
                        className="animate-fade-in-up opacity-0"
                        style={{ animationDelay: `${idx * 30}ms` }}
                      >
                        <td>
                          <div className="text-foreground">{target.web_name}</div>
                          <div className="text-xs text-foreground-dim">{teamsMap.get(target.team)}</div>
                        </td>
                        <td className="text-center text-foreground-muted font-mono">
                          {getPositionName(target.position)}
                        </td>
                        <td className="text-center text-foreground-muted font-mono">
                          £{(target.now_cost / 10).toFixed(1)}m
                        </td>
                        <td className="text-center text-foreground-muted font-mono">
                          {target.form.toFixed(1)}
                        </td>
                        <td className="text-center">
                          <span className="text-fpl-green font-mono font-bold">
                            {target.predicted_points.toFixed(1)}
                          </span>
                        </td>
                        <td className="text-center text-position-mid font-mono">
                          {target.value_score.toFixed(2)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </BroadcastCard>
          ) : (
            <BroadcastCard>
              <div className="py-8 text-center text-foreground-muted">
                No transfer targets available
              </div>
            </BroadcastCard>
          )}
        </div>
      )}

      {viewMode === 'optimize' && !managerId && (
        <EmptyState
          icon={<ChartIcon size={64} />}
          title="Enter Your Manager ID"
          description="Get multi-week transfer optimization with chip timing suggestions."
        />
      )}
    </div>
  )
}
