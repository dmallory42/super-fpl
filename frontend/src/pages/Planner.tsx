import { useState, useMemo, useEffect, useRef } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { usePlannerOptimize } from '../hooks/usePlannerOptimize'
import { usePredictionsRange } from '../hooks/usePredictionsRange'
import type {
  ChipPlan,
  PlayerMultiWeekPrediction,
  CaptainCandidate,
  TransferPath,
  FixedTransfer,
  SolverDepth,
} from '../api/client'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { EmptyState, ChartIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonCard } from '../components/ui/SkeletonLoader'
import { FormationPitch } from '../components/live/FormationPitch'
import { useLiveSamples } from '../hooks/useLiveSamples'
import { PlayerExplorer } from '../components/planner/PlayerExplorer'
import { buildFormation } from '../lib/formation'

const HIT_COST = 4

interface StagedTransfer {
  out: number
  in: number
  outName: string
  inName: string
  outPrice: number
  inPrice: number
}

function getInitialManagerId(): { id: number | null; input: string } {
  const params = new URLSearchParams(window.location.search)
  const urlManager = params.get('manager')
  if (urlManager) {
    const id = parseInt(urlManager, 10)
    if (!isNaN(id) && id > 0) {
      return { id, input: urlManager }
    }
  }
  const savedId = localStorage.getItem('fpl_manager_id')
  if (savedId) {
    const id = parseInt(savedId, 10)
    if (!isNaN(id) && id > 0) {
      return { id, input: savedId }
    }
  }
  return { id: null, input: '' }
}

export function Planner() {
  const initial = getInitialManagerId()
  const [managerId, setManagerId] = useState<number | null>(initial.id)
  const [managerInput, setManagerInput] = useState(initial.input)
  const [freeTransfers, setFreeTransfers] = useState(1)
  const chipPlan: ChipPlan = {}
  const [xMinsOverrides, setXMinsOverrides] = useState<Record<number, number>>({})
  const [selectedGameweek, setSelectedGameweek] = useState<number | null>(null)

  // Path solver state
  const [selectedPathIndex, setSelectedPathIndex] = useState<number | null>(null)
  const [ftValue, setFtValue] = useState(1.5)
  const [solverDepth, setSolverDepth] = useState<SolverDepth>('standard')
  const [fixedTransfers, setFixedTransfers] = useState<FixedTransfer[]>([])

  // Transfer planning state
  const [stagedTransfers, setStagedTransfers] = useState<StagedTransfer[]>([])
  const [selectedForTransferOut, setSelectedForTransferOut] = useState<number | null>(null)
  const [replacementSearch, setReplacementSearch] = useState('')

  // Debounce xMinsOverrides so the optimize API call doesn't fire on every keystroke
  const [debouncedXMins, setDebouncedXMins] = useState<Record<number, number>>(xMinsOverrides)
  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  useEffect(() => {
    if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current)
    debounceTimerRef.current = setTimeout(() => {
      setDebouncedXMins(xMinsOverrides)
    }, 800)
    return () => {
      if (debounceTimerRef.current) clearTimeout(debounceTimerRef.current)
    }
  }, [xMinsOverrides])

  const { data: playersData } = usePlayers()
  const {
    data: optimizeData,
    isLoading: isLoadingOptimize,
    error: optimizeError,
  } = usePlannerOptimize(
    managerId,
    freeTransfers,
    chipPlan,
    debouncedXMins,
    fixedTransfers,
    ftValue,
    solverDepth
  )

  // Get predictions for multiple gameweeks
  const { data: predictionsRange } = usePredictionsRange()

  // Get top 10k effective ownership for player explorer
  const { data: samplesData } = useLiveSamples(predictionsRange?.current_gameweek ?? null)
  const top10kEO = samplesData?.samples?.top_10k?.effective_ownership

  // Initialize selectedGameweek when data loads - use current_gameweek from API
  useEffect(() => {
    if (predictionsRange && selectedGameweek === null) {
      // Prefer current_gameweek, fall back to first in the list
      const initialGw = predictionsRange.current_gameweek || predictionsRange.gameweeks?.[0]
      if (initialGw) {
        setSelectedGameweek(initialGw)
      }
    }
  }, [predictionsRange, selectedGameweek])

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map<number, string>()
    return new Map(playersData.teams.map((t) => [t.id, t.short_name]))
  }, [playersData?.teams])

  // Teams record for FormationPitch
  const teamsRecord = useMemo(() => {
    if (!playersData?.teams) return {}
    return Object.fromEntries(
      playersData.teams.map((t) => [t.id, { id: t.id, short_name: t.short_name }])
    )
  }, [playersData?.teams])

  // Player predictions map for quick lookup
  const playerPredictionsMap = useMemo(() => {
    if (!predictionsRange?.players) return new Map<number, PlayerMultiWeekPrediction>()
    return new Map(predictionsRange.players.map((p) => [p.player_id, p]))
  }, [predictionsRange?.players])

  // Selected path from solver
  const selectedPath: TransferPath | null = useMemo(() => {
    if (selectedPathIndex === null || !optimizeData?.paths) return null
    return optimizeData.paths[selectedPathIndex] ?? null
  }, [selectedPathIndex, optimizeData?.paths])

  // Current squad: when a path is selected, use the path's squad for the selected GW;
  // otherwise use the original squad with staged manual transfers applied
  const effectiveSquad = useMemo(() => {
    if (!optimizeData?.current_squad?.player_ids) return []

    // Path mode: use the path's squad_ids for the selected GW
    if (selectedPath && selectedGameweek !== null) {
      const gwData = selectedPath.transfers_by_gw[selectedGameweek]
      if (gwData?.squad_ids) return gwData.squad_ids
    }

    // Manual mode: original squad + staged transfers
    let squad = [...optimizeData.current_squad.player_ids]
    for (const transfer of stagedTransfers) {
      squad = squad.filter((id) => id !== transfer.out)
      squad.push(transfer.in)
    }
    return squad
  }, [optimizeData?.current_squad?.player_ids, stagedTransfers, selectedPath, selectedGameweek])

  // Calculate bank after staged transfers
  const effectiveBank = useMemo(() => {
    if (!optimizeData?.current_squad?.bank) return 0
    let bank = optimizeData.current_squad.bank

    for (const transfer of stagedTransfers) {
      bank += transfer.outPrice - transfer.inPrice
    }

    return Math.round(bank * 10) / 10
  }, [optimizeData?.current_squad?.bank, stagedTransfers])

  // Calculate hits for staged transfers
  const hitsCount = Math.max(0, stagedTransfers.length - freeTransfers)
  const hitsCost = hitsCount * HIT_COST

  // Get the player being transferred out
  const selectedOutPlayer = useMemo(() => {
    if (!selectedForTransferOut || !playersData?.players) return null
    return playersData.players.find((p) => p.id === selectedForTransferOut)
  }, [selectedForTransferOut, playersData?.players])

  // Available replacements for the selected player
  const availableReplacements = useMemo(() => {
    if (!selectedOutPlayer || !predictionsRange?.players || !playersData?.players) return []

    const position = selectedOutPlayer.element_type
    const maxBudget = selectedOutPlayer.now_cost / 10 + effectiveBank

    // Count players per team in effective squad
    const teamCounts = new Map<number, number>()
    for (const playerId of effectiveSquad) {
      const player = playersData.players.find((p) => p.id === playerId)
      if (player) {
        teamCounts.set(player.team, (teamCounts.get(player.team) || 0) + 1)
      }
    }

    return predictionsRange.players
      .filter((p) => {
        // Same position
        if (p.position !== position) return false
        // Not already in squad
        if (effectiveSquad.includes(p.player_id)) return false
        // Within budget
        if (p.now_cost / 10 > maxBudget) return false
        // Team limit (max 3)
        const currentTeamCount = teamCounts.get(p.team) || 0
        // Don't count the outgoing player's team if it's the same
        const adjustedCount =
          p.team === selectedOutPlayer.team ? currentTeamCount - 1 : currentTeamCount
        if (adjustedCount >= 3) return false
        // Search filter
        if (
          replacementSearch &&
          !p.web_name.toLowerCase().includes(replacementSearch.toLowerCase())
        ) {
          return false
        }
        return true
      })
      .sort((a, b) => b.total_predicted - a.total_predicted)
      .slice(0, 50)
  }, [
    selectedOutPlayer,
    predictionsRange?.players,
    playersData?.players,
    effectiveSquad,
    effectiveBank,
    replacementSearch,
  ])

  // Persist manager ID to URL and localStorage
  useEffect(() => {
    if (managerId) {
      localStorage.setItem('fpl_manager_id', String(managerId))
      const params = new URLSearchParams(window.location.search)
      params.set('manager', String(managerId))
      window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`)
    }
  }, [managerId])

  const handleLoadManager = () => {
    const id = parseInt(managerInput, 10)
    if (!isNaN(id) && id > 0) {
      setManagerId(id)
      setStagedTransfers([])
      setSelectedForTransferOut(null)
      setSelectedPathIndex(null)
      setFixedTransfers([])
    }
  }

  const handleSelectForTransferOut = (playerId: number) => {
    // If already selected, deselect
    if (selectedForTransferOut === playerId) {
      setSelectedForTransferOut(null)
      setReplacementSearch('')
    } else {
      setSelectedForTransferOut(playerId)
      setReplacementSearch('')
    }
  }

  const handleSelectReplacement = (replacement: PlayerMultiWeekPrediction) => {
    if (!selectedOutPlayer) return

    const newTransfer: StagedTransfer = {
      out: selectedOutPlayer.id,
      in: replacement.player_id,
      outName: selectedOutPlayer.web_name,
      inName: replacement.web_name,
      outPrice: selectedOutPlayer.now_cost / 10,
      inPrice: replacement.now_cost / 10,
    }

    setStagedTransfers([...stagedTransfers, newTransfer])
    setSelectedForTransferOut(null)
    setReplacementSearch('')
  }

  const handleRemoveTransfer = (index: number) => {
    setStagedTransfers(stagedTransfers.filter((_, i) => i !== index))
  }

  const handleResetTransfers = () => {
    setStagedTransfers([])
    setSelectedForTransferOut(null)
    setReplacementSearch('')
    setSelectedPathIndex(null)
    setFixedTransfers([])
  }

  const handleXMinsChange = (playerId: number, xMins: number) => {
    setXMinsOverrides((prev) => ({
      ...prev,
      [playerId]: xMins,
    }))
  }

  // Get captain candidates from API formation data for selected GW
  const captainCandidates: CaptainCandidate[] = useMemo(() => {
    if (!optimizeData?.current_squad?.formations || selectedGameweek === null) return []
    const formation = optimizeData.current_squad.formations?.[selectedGameweek]
    return formation?.captain_candidates ?? []
  }, [optimizeData?.current_squad?.formations, selectedGameweek])

  // Build formation players for the selected gameweek
  const formationPlayers = useMemo(() => {
    if (!effectiveSquad.length || !playersData?.players || selectedGameweek === null) return []

    // Map squad player IDs to player data with GW predictions
    const squadWithData = effectiveSquad
      .map((playerId) => {
        const player = playersData.players.find((p) => p.id === playerId)
        const pred = playerPredictionsMap.get(playerId)
        if (!player) return null

        return {
          player_id: playerId,
          web_name: player.web_name,
          element_type: player.element_type,
          team: player.team,
          predicted_points: pred?.predictions[selectedGameweek] ?? 0,
        }
      })
      .filter((p): p is NonNullable<typeof p> => p !== null)

    return buildFormation(squadWithData)
  }, [effectiveSquad, playersData?.players, playerPredictionsMap, selectedGameweek])

  // Calculate predicted points for effective squad
  const squadPredictions = useMemo(() => {
    if (!predictionsRange?.gameweeks || !playerPredictionsMap.size) return null

    const byGw: Record<number, number> = {}
    let total = 0

    for (const gw of predictionsRange.gameweeks) {
      let gwTotal = 0
      let maxPts = 0

      for (const playerId of effectiveSquad) {
        const pred = playerPredictionsMap.get(playerId)
        const pts = pred?.predictions[gw] ?? 0
        gwTotal += pts
        if (pts > maxPts) maxPts = pts
      }

      // Add captain bonus (best player counts twice)
      gwTotal += maxPts

      // Subtract hits from first gameweek
      if (gw === predictionsRange.gameweeks[0]) {
        gwTotal -= hitsCost
      }

      byGw[gw] = Math.round(gwTotal * 10) / 10
      total += byGw[gw]
    }

    return { byGw, total: Math.round(total * 10) / 10 }
  }, [effectiveSquad, predictionsRange, playerPredictionsMap, hitsCost])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="animate-fade-in-up">
        <h2 className="font-display text-2xl font-bold tracking-wider uppercase text-foreground mb-2">
          Transfer Planner
        </h2>
        <p className="font-body text-foreground-muted text-sm mb-4">
          Plan transfers and see projected points. Click players to swap them out.
        </p>
      </div>

      {/* Controls */}
      <div className="grid md:grid-cols-3 gap-4 animate-fade-in-up animation-delay-100">
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
            {[1, 2, 3, 4, 5].map((n) => (
              <option key={n} value={n}>
                {n} FT
              </option>
            ))}
          </select>
        </div>

        {stagedTransfers.length > 0 && (
          <div className="flex items-end">
            <button onClick={handleResetTransfers} className="btn-secondary w-full">
              Reset Transfers
            </button>
          </div>
        )}
      </div>

      {optimizeError && (
        <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
          {optimizeError.message || 'Failed to load optimization data'}
        </div>
      )}

      {isLoadingOptimize && managerId && (
        <div className="space-y-6">
          <SkeletonStatGrid />
          <SkeletonCard lines={4} />
        </div>
      )}

      {optimizeData && (
        <div className="grid lg:grid-cols-3 gap-6">
          {/* Main Content - Squad & Predictions */}
          <div className="lg:col-span-2 space-y-6">
            {/* Squad Summary */}
            <StatPanelGrid>
              <StatPanel
                label="Squad Value"
                value={`£${optimizeData.current_squad.squad_value.toFixed(1)}m`}
                animationDelay={0}
              />
              <StatPanel
                label="Bank"
                value={`£${effectiveBank.toFixed(1)}m`}
                animationDelay={50}
                highlight={effectiveBank !== optimizeData.current_squad.bank}
              />
              <StatPanel
                label="Transfers"
                value={`${stagedTransfers.length} (${hitsCount > 0 ? `-${hitsCost} pts` : 'Free'})`}
                animationDelay={100}
                highlight={hitsCount > 0}
              />
              <StatPanel
                label={`Projected (${predictionsRange?.gameweeks.length ?? 6} GWs)`}
                value={`${squadPredictions?.total ?? '...'} pts`}
                highlight
                animationDelay={150}
              />
            </StatPanelGrid>

            {/* Path Selector */}
            {optimizeData.paths && optimizeData.paths.length > 0 && (
              <BroadcastCard title="Recommended Paths" accentColor="purple" animationDelay={175}>
                <div className="grid grid-cols-3 gap-3 mb-4">
                  {optimizeData.paths.map((path, idx) => {
                    const isSelected = selectedPathIndex === idx
                    const horizon = optimizeData.planning_horizon
                    return (
                      <button
                        key={path.id}
                        onClick={() => {
                          if (isSelected) {
                            setSelectedPathIndex(null)
                          } else {
                            setSelectedPathIndex(idx)
                            setStagedTransfers([])
                            setSelectedForTransferOut(null)
                          }
                        }}
                        className={`p-3 rounded-lg text-left transition-all animate-fade-in-up opacity-0 ${
                          isSelected
                            ? 'bg-fpl-purple/20 border-2 border-fpl-purple ring-2 ring-fpl-purple/30'
                            : 'bg-surface-elevated hover:bg-surface-hover border border-transparent'
                        }`}
                        style={{ animationDelay: `${200 + idx * 50}ms` }}
                      >
                        <div className="flex items-center justify-between mb-2">
                          <span className="font-display text-xs uppercase tracking-wider text-foreground-muted">
                            Path {path.id}
                          </span>
                          {path.total_hits > 0 && (
                            <span className="text-xs text-destructive font-mono">
                              {path.total_hits} hit{path.total_hits > 1 ? 's' : ''}
                            </span>
                          )}
                        </div>
                        <div
                          className={`font-mono text-lg font-bold ${
                            path.score_vs_hold > 0 ? 'text-fpl-green' : 'text-foreground'
                          }`}
                        >
                          {path.score_vs_hold > 0 ? '+' : ''}
                          {path.score_vs_hold.toFixed(1)} pts
                        </div>
                        <div className="mt-2 space-y-1">
                          {horizon.map((gw) => {
                            const gwData = path.transfers_by_gw[gw]
                            if (!gwData) return null
                            return (
                              <div key={gw} className="text-xs text-foreground-muted truncate">
                                <span className="text-foreground-dim">GW{gw}:</span>{' '}
                                {gwData.action === 'bank' ? (
                                  <span className="text-foreground-dim">Bank</span>
                                ) : (
                                  gwData.moves.map((m, mi) => (
                                    <span key={mi}>
                                      {mi > 0 && ', '}
                                      <span className="text-destructive/70">{m.out_name}</span>
                                      <span className="text-foreground-dim">{' \u2192 '}</span>
                                      <span className="text-fpl-green/70">{m.in_name}</span>
                                    </span>
                                  ))
                                )}
                              </div>
                            )
                          })}
                        </div>
                      </button>
                    )
                  })}
                </div>

                {/* Solver Controls */}
                <div className="pt-3 border-t border-border/50 flex flex-wrap items-center gap-4">
                  {/* FT Value Slider */}
                  <div className="flex items-center gap-2 flex-1 min-w-[200px]">
                    <label className="font-display text-xs uppercase tracking-wider text-foreground-muted whitespace-nowrap">
                      FT Value
                    </label>
                    <input
                      type="range"
                      min="0"
                      max="5"
                      step="0.5"
                      value={ftValue}
                      onChange={(e) => {
                        setFtValue(parseFloat(e.target.value))
                        setSelectedPathIndex(null)
                      }}
                      className="flex-1 accent-fpl-purple"
                    />
                    <span className="font-mono text-sm text-foreground w-10 text-right">
                      {ftValue.toFixed(1)}
                    </span>
                  </div>

                  {/* Depth Toggle */}
                  <div className="flex gap-1">
                    {(['quick', 'standard', 'deep'] as const).map((d) => (
                      <button
                        key={d}
                        onClick={() => {
                          setSolverDepth(d)
                          setSelectedPathIndex(null)
                        }}
                        className={`px-3 py-1 rounded text-xs font-display uppercase tracking-wider transition-colors ${
                          solverDepth === d
                            ? 'bg-fpl-purple/20 text-fpl-purple border border-fpl-purple/30'
                            : 'text-foreground-muted hover:text-foreground hover:bg-surface-hover'
                        }`}
                      >
                        {d}
                      </button>
                    ))}
                  </div>
                </div>
              </BroadcastCard>
            )}

            {/* Gameweek Selector */}
            <BroadcastCard title="Select Gameweek" animationDelay={200}>
              <div className="flex gap-2 flex-wrap">
                {predictionsRange?.gameweeks.map((gw, idx) => {
                  const pathGw = selectedPath?.transfers_by_gw[gw]
                  const pts = pathGw ? pathGw.gw_score : (squadPredictions?.byGw[gw] ?? 0)
                  const isFirst = idx === 0
                  const isSelected = gw === selectedGameweek
                  const pathAction = pathGw?.action
                  return (
                    <button
                      key={gw}
                      onClick={() => setSelectedGameweek(gw)}
                      className={`
                        px-4 py-3 rounded-lg animate-fade-in-up opacity-0 transition-all
                        ${
                          isSelected
                            ? 'bg-fpl-green/20 border-2 border-fpl-green ring-2 ring-fpl-green/30'
                            : isFirst && hitsCost > 0 && !selectedPath
                              ? 'bg-destructive/20 border border-destructive/30 hover:bg-destructive/30'
                              : pathAction === 'transfer'
                                ? 'bg-fpl-purple/10 border border-fpl-purple/20 hover:bg-fpl-purple/20'
                                : 'bg-surface-elevated hover:bg-surface-hover border border-transparent'
                        }
                      `}
                      style={{ animationDelay: `${250 + idx * 50}ms` }}
                    >
                      <div
                        className={`text-xs font-display uppercase ${isSelected ? 'text-fpl-green' : 'text-foreground-muted'}`}
                      >
                        GW{gw}
                        {!selectedPath && isFirst && hitsCost > 0 && ` (-${hitsCost})`}
                        {pathGw?.hit_cost ? ` (-${pathGw.hit_cost})` : ''}
                      </div>
                      <div
                        className={`text-xl font-mono font-bold ${isSelected ? 'text-fpl-green' : 'text-foreground'}`}
                      >
                        {pts.toFixed(1)}
                      </div>
                      {pathAction && (
                        <div className="text-[10px] text-fpl-purple mt-0.5">
                          {pathAction === 'bank'
                            ? 'Bank'
                            : `${pathGw.moves.length} move${pathGw.moves.length > 1 ? 's' : ''}`}
                        </div>
                      )}
                    </button>
                  )
                })}
              </div>
              <div className="mt-3 pt-3 border-t border-border/50 flex items-center justify-between">
                <span className="text-xs text-foreground-muted">
                  Total ({predictionsRange?.gameweeks.length ?? 6} GWs)
                  {selectedPath && ' — Path ' + selectedPath.id}
                </span>
                <span className="font-mono font-bold text-fpl-green text-lg">
                  {selectedPath
                    ? `${selectedPath.total_score.toFixed(1)} pts`
                    : `${squadPredictions?.total.toFixed(1) ?? '...'} pts`}
                </span>
              </div>
            </BroadcastCard>

            {/* Squad Formation */}
            <BroadcastCard
              title={`Your Squad — GW${selectedGameweek ?? '?'} Predictions`}
              accentColor="green"
              animationDelay={300}
            >
              <p className="text-xs text-foreground-muted mb-4">
                {selectedPath
                  ? `Showing Path ${selectedPath.id} squad for GW${selectedGameweek ?? '?'}. Click a different GW to see the squad at that point.`
                  : `Click a player to transfer them out. Hover and click ✎ to edit expected minutes. Points shown are for GW${selectedGameweek ?? '?'}.`}
              </p>
              {formationPlayers.length > 0 ? (
                <FormationPitch
                  players={formationPlayers}
                  teams={teamsRecord}
                  editable={!selectedPath}
                  xMinsOverrides={xMinsOverrides}
                  onXMinsChange={handleXMinsChange}
                  transferMode={!selectedPath}
                  selectedForTransfer={selectedForTransferOut}
                  newTransferIds={
                    selectedPath && selectedGameweek !== null
                      ? // Highlight all players brought in by this path up to the selected GW
                        (() => {
                          const originalSquad = new Set(optimizeData.current_squad.player_ids)
                          const gwData = selectedPath.transfers_by_gw[selectedGameweek]
                          const currentSquad = gwData?.squad_ids ?? []
                          return currentSquad.filter((id) => !originalSquad.has(id))
                        })()
                      : stagedTransfers.map((t) => t.in)
                  }
                  onPlayerClick={selectedPath ? undefined : handleSelectForTransferOut}
                />
              ) : (
                <div className="text-center text-foreground-muted py-8">Loading squad...</div>
              )}
            </BroadcastCard>

            {/* Captain Decision Panel */}
            {captainCandidates.length > 1 && (
              <BroadcastCard title="Captain Decision" accentColor="highlight" animationDelay={350}>
                <p className="text-xs text-foreground-muted mb-3">
                  Multiple players within 0.5 pts — consider form, fixtures, and gut feel.
                </p>
                <div className="space-y-2">
                  {captainCandidates.map((c, idx) => {
                    const player = playersData?.players.find((p) => p.id === c.player_id)
                    if (!player) return null
                    return (
                      <div
                        key={c.player_id}
                        className={`flex items-center justify-between p-3 rounded-lg animate-fade-in-up opacity-0 ${
                          idx === 0
                            ? 'bg-yellow-400/10 border border-yellow-400/30'
                            : 'bg-surface-elevated'
                        }`}
                        style={{ animationDelay: `${400 + idx * 50}ms` }}
                      >
                        <div className="flex items-center gap-3">
                          {idx === 0 && (
                            <span className="text-yellow-400 font-display text-xs uppercase tracking-wider">
                              Top
                            </span>
                          )}
                          <span className="text-foreground font-medium">{player.web_name}</span>
                          <span className="text-xs text-foreground-dim">
                            {teamsMap.get(player.team)}
                          </span>
                        </div>
                        <div className="text-right">
                          <span className="font-mono font-bold text-fpl-green">
                            {c.predicted_points.toFixed(1)}
                          </span>
                          {c.margin > 0 && (
                            <span className="text-xs text-foreground-muted ml-2">
                              -{c.margin.toFixed(1)}
                            </span>
                          )}
                        </div>
                      </div>
                    )
                  })}
                </div>
              </BroadcastCard>
            )}
          </div>

          {/* Sidebar - Transfers & Replacements */}
          <div className="space-y-6">
            {/* Path GW Moves — shown when a path is selected */}
            {selectedPath &&
              selectedGameweek !== null &&
              (() => {
                const gwData = selectedPath.transfers_by_gw[selectedGameweek]
                if (!gwData) return null
                return (
                  <BroadcastCard
                    title={`Path ${selectedPath.id} — GW${selectedGameweek}`}
                    accentColor="purple"
                    animationDelay={200}
                  >
                    <div className="space-y-3">
                      {/* FT info */}
                      <div className="flex items-center justify-between text-xs text-foreground-muted">
                        <span>{gwData.ft_available} FT available</span>
                        <span>{gwData.ft_after} FT after</span>
                      </div>

                      {gwData.action === 'bank' ? (
                        <div className="p-3 bg-surface-elevated rounded-lg text-center">
                          <span className="font-display text-sm uppercase tracking-wider text-foreground-muted">
                            Bank — save FT for later
                          </span>
                        </div>
                      ) : (
                        gwData.moves.map((move, idx) => (
                          <div
                            key={idx}
                            className="p-3 bg-surface-elevated rounded-lg animate-fade-in-up opacity-0"
                            style={{ animationDelay: `${250 + idx * 50}ms` }}
                          >
                            <div className="flex items-center justify-between mb-2">
                              <span
                                className={`text-xs font-display uppercase ${move.is_free ? 'text-fpl-green' : 'text-destructive'}`}
                              >
                                {move.is_free ? 'Free Transfer' : `-${HIT_COST} pt Hit`}
                              </span>
                              <span
                                className={`text-xs font-mono ${move.gain > 0 ? 'text-fpl-green' : move.gain < 0 ? 'text-destructive' : 'text-foreground-muted'}`}
                              >
                                {move.gain > 0 ? '+' : ''}
                                {move.gain.toFixed(1)} pts
                              </span>
                            </div>
                            <div className="flex items-center gap-2 text-sm">
                              <span className="text-destructive">
                                {move.out_name}
                                <span className="text-foreground-dim text-xs ml-1">
                                  {teamsMap.get(move.out_team)}
                                </span>
                              </span>
                              <span className="text-foreground-dim">{'\u2192'}</span>
                              <span className="text-fpl-green">
                                {move.in_name}
                                <span className="text-foreground-dim text-xs ml-1">
                                  {teamsMap.get(move.in_team)}
                                </span>
                              </span>
                            </div>
                            <div className="flex items-center justify-between mt-1 text-xs text-foreground-muted">
                              <span>
                                {'\u00A3'}
                                {move.out_price.toFixed(1)}m {'\u2192'} {'\u00A3'}
                                {move.in_price.toFixed(1)}m
                              </span>
                            </div>
                          </div>
                        ))
                      )}

                      {gwData.hit_cost > 0 && (
                        <div className="text-xs text-destructive text-center">
                          Hit cost: -{gwData.hit_cost} pts
                        </div>
                      )}

                      <div className="pt-2 border-t border-border/50 flex items-center justify-between">
                        <span className="text-xs text-foreground-muted">GW Score</span>
                        <span className="font-mono font-bold text-fpl-green">
                          {gwData.gw_score.toFixed(1)} pts
                        </span>
                      </div>

                      <button
                        onClick={() => setSelectedPathIndex(null)}
                        className="btn-secondary w-full"
                      >
                        Exit Path View
                      </button>
                    </div>
                  </BroadcastCard>
                )
              })()}

            {/* Staged Transfers — shown in manual mode (no path selected) */}
            {!selectedPath && stagedTransfers.length > 0 && (
              <BroadcastCard title="Staged Transfers" accentColor="purple" animationDelay={200}>
                <div className="space-y-3">
                  {stagedTransfers.map((transfer, idx) => {
                    const isFree = idx < freeTransfers
                    const outPred = playerPredictionsMap.get(transfer.out)
                    const inPred = playerPredictionsMap.get(transfer.in)
                    const gain = (inPred?.total_predicted ?? 0) - (outPred?.total_predicted ?? 0)

                    return (
                      <div
                        key={idx}
                        className="p-3 bg-surface-elevated rounded-lg animate-fade-in-up opacity-0"
                        style={{ animationDelay: `${250 + idx * 50}ms` }}
                      >
                        <div className="flex items-center justify-between mb-2">
                          <span
                            className={`text-xs font-display uppercase ${isFree ? 'text-fpl-green' : 'text-destructive'}`}
                          >
                            {isFree ? 'Free Transfer' : `-${HIT_COST} pt Hit`}
                          </span>
                          <button
                            onClick={() => handleRemoveTransfer(idx)}
                            className="text-xs text-foreground-muted hover:text-destructive"
                          >
                            Remove
                          </button>
                        </div>
                        <div className="flex items-center gap-2 text-sm">
                          <span className="text-destructive">{transfer.outName}</span>
                          <span className="text-foreground-dim">{'\u2192'}</span>
                          <span className="text-fpl-green">{transfer.inName}</span>
                        </div>
                        <div className="flex items-center justify-between mt-1 text-xs text-foreground-muted">
                          <span>
                            {'\u00A3'}
                            {transfer.outPrice.toFixed(1)}m {'\u2192'} {'\u00A3'}
                            {transfer.inPrice.toFixed(1)}m
                          </span>
                          <span
                            className={
                              gain > 0 ? 'text-fpl-green' : gain < 0 ? 'text-destructive' : ''
                            }
                          >
                            {gain > 0 ? '+' : ''}
                            {gain.toFixed(1)} pts
                          </span>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </BroadcastCard>
            )}

            {/* Replacement Picker — only in manual mode */}
            {!selectedPath && selectedOutPlayer && (
              <BroadcastCard
                title={`Replace ${selectedOutPlayer.web_name}`}
                accentColor="green"
                animationDelay={0}
              >
                <div className="space-y-3">
                  <div className="text-xs text-foreground-muted">
                    Budget: {'\u00A3'}
                    {(selectedOutPlayer.now_cost / 10 + effectiveBank).toFixed(1)}m
                  </div>
                  <input
                    type="text"
                    value={replacementSearch}
                    onChange={(e) => setReplacementSearch(e.target.value)}
                    placeholder="Search players..."
                    className="input-broadcast"
                    autoFocus
                  />
                  <div className="max-h-[400px] overflow-y-auto space-y-1">
                    {availableReplacements.map((player, idx) => {
                      const currentPred = playerPredictionsMap.get(selectedOutPlayer.id)
                      const gain = player.total_predicted - (currentPred?.total_predicted ?? 0)

                      return (
                        <button
                          key={player.player_id}
                          onClick={() => handleSelectReplacement(player)}
                          className="w-full flex items-center gap-2 p-2 rounded hover:bg-surface-hover text-left transition-colors animate-fade-in-up opacity-0"
                          style={{ animationDelay: `${idx * 20}ms` }}
                        >
                          <div className="flex-1 min-w-0">
                            <div className="text-foreground font-medium text-sm truncate">
                              {player.web_name}
                            </div>
                            <div className="text-xs text-foreground-dim">
                              {teamsMap.get(player.team)} · {'\u00A3'}
                              {(player.now_cost / 10).toFixed(1)}m
                            </div>
                          </div>
                          <div className="text-right">
                            <div className="text-fpl-green font-mono text-sm font-bold">
                              {player.total_predicted.toFixed(1)}
                            </div>
                            <div
                              className={`text-xs font-mono ${gain > 0 ? 'text-fpl-green' : gain < 0 ? 'text-destructive' : 'text-foreground-muted'}`}
                            >
                              {gain > 0 ? '+' : ''}
                              {gain.toFixed(1)}
                            </div>
                          </div>
                        </button>
                      )
                    })}
                    {availableReplacements.length === 0 && (
                      <div className="text-center text-foreground-muted py-4 text-sm">
                        No replacements found
                      </div>
                    )}
                  </div>
                  <button
                    onClick={() => setSelectedForTransferOut(null)}
                    className="btn-secondary w-full"
                  >
                    Cancel
                  </button>
                </div>
              </BroadcastCard>
            )}

            {/* Quick Tips when no path and no player selected */}
            {!selectedPath && !selectedForTransferOut && stagedTransfers.length === 0 && (
              <BroadcastCard title="How to Use" animationDelay={400}>
                <ul className="text-sm text-foreground-muted space-y-2">
                  <li>{'•'} Select a recommended path to see the full multi-GW plan</li>
                  <li>{'•'} Click GW tabs to see the squad at each point in the path</li>
                  <li>{'•'} Or click any player in your squad to manually plan transfers</li>
                  <li>{'•'} Adjust FT Value and Depth to tune solver behavior</li>
                </ul>
              </BroadcastCard>
            )}
          </div>
        </div>
      )}

      {/* Player Explorer - always visible when predictions loaded */}
      {predictionsRange && (
        <PlayerExplorer
          players={predictionsRange.players}
          gameweeks={predictionsRange.gameweeks}
          teamsMap={teamsMap}
          effectiveOwnership={top10kEO}
          xMinsOverrides={xMinsOverrides}
          onXMinsChange={handleXMinsChange}
          onResetXMins={() => setXMinsOverrides({})}
        />
      )}

      {!managerId && (
        <EmptyState
          icon={<ChartIcon size={64} />}
          title="Enter Your Manager ID"
          description="Plan transfers and see projected points for your squad."
        />
      )}
    </div>
  )
}
