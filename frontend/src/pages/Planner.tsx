import { useState, useMemo, useEffect } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { usePlannerOptimize } from '../hooks/usePlannerOptimize'
import { usePredictionsRange } from '../hooks/usePredictionsRange'
import type { ChipPlan, PlayerMultiWeekPrediction } from '../api/client'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { EmptyState, ChartIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonCard } from '../components/ui/SkeletonLoader'
import { FormationPitch } from '../components/live/FormationPitch'

const HIT_COST = 4

interface StagedTransfer {
  out: number
  in: number
  outName: string
  inName: string
  outPrice: number
  inPrice: number
}

export function Planner() {
  const [managerId, setManagerId] = useState<number | null>(null)
  const [managerInput, setManagerInput] = useState('')
  const [freeTransfers, setFreeTransfers] = useState(1)
  const [chipPlan] = useState<ChipPlan>({})
  const [xMinsOverrides, setXMinsOverrides] = useState<Record<number, number>>({})
  const [selectedGameweek, setSelectedGameweek] = useState<number | null>(null)

  // Transfer planning state
  const [stagedTransfers, setStagedTransfers] = useState<StagedTransfer[]>([])
  const [selectedForTransferOut, setSelectedForTransferOut] = useState<number | null>(null)
  const [replacementSearch, setReplacementSearch] = useState('')

  const { data: playersData } = usePlayers()
  const {
    data: optimizeData,
    isLoading: isLoadingOptimize,
    error: optimizeError,
  } = usePlannerOptimize(managerId, freeTransfers, chipPlan, xMinsOverrides)

  // Get predictions for multiple gameweeks
  const { data: predictionsRange } = usePredictionsRange()

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

  // Current squad with staged transfers applied
  const effectiveSquad = useMemo(() => {
    if (!optimizeData?.current_squad?.player_ids) return []
    let squad = [...optimizeData.current_squad.player_ids]

    // Apply staged transfers
    for (const transfer of stagedTransfers) {
      squad = squad.filter((id) => id !== transfer.out)
      squad.push(transfer.in)
    }

    return squad
  }, [optimizeData?.current_squad?.player_ids, stagedTransfers])

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

  const handleLoadManager = () => {
    const id = parseInt(managerInput, 10)
    if (!isNaN(id) && id > 0) {
      setManagerId(id)
      setStagedTransfers([])
      setSelectedForTransferOut(null)
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
  }

  const handleXMinsChange = (playerId: number, xMins: number) => {
    setXMinsOverrides((prev) => ({
      ...prev,
      [playerId]: xMins,
    }))
  }

  // Build formation players for the selected gameweek
  const formationPlayers = useMemo(() => {
    if (!effectiveSquad.length || !playersData?.players || selectedGameweek === null) return []

    // Map squad player IDs to full player data with predictions
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
          multiplier: 1,
          position: 0, // Will be set below
          predicted_points: pred?.predictions[selectedGameweek] ?? 0,
          total_predicted: pred?.total_predicted ?? 0,
        }
      })
      .filter(Boolean) as Array<{
      player_id: number
      web_name: string
      element_type: number
      team: number
      multiplier: number
      position: number
      predicted_points: number
      total_predicted: number
      is_captain?: boolean
    }>

    // Group by position
    const gks = squadWithData
      .filter((p) => p.element_type === 1)
      .sort((a, b) => b.predicted_points - a.predicted_points)
    const defs = squadWithData
      .filter((p) => p.element_type === 2)
      .sort((a, b) => b.predicted_points - a.predicted_points)
    const mids = squadWithData
      .filter((p) => p.element_type === 3)
      .sort((a, b) => b.predicted_points - a.predicted_points)
    const fwds = squadWithData
      .filter((p) => p.element_type === 4)
      .sort((a, b) => b.predicted_points - a.predicted_points)

    // Select starting XI (basic strategy: pick best by position with valid formation)
    // GK: 1, DEF: 3-5, MID: 2-5, FWD: 1-3, total = 11
    const startingGks = gks.slice(0, 1)
    const startingDefs = defs.slice(0, Math.min(defs.length, 5))
    const startingMids = mids.slice(0, Math.min(mids.length, 5))
    const startingFwds = fwds.slice(0, Math.min(fwds.length, 3))

    // Ensure we have 11 starters with valid formation
    let total = startingGks.length + startingDefs.length + startingMids.length + startingFwds.length
    const starting = [...startingGks, ...startingDefs, ...startingMids, ...startingFwds]

    // If we have too many, trim from the lowest scorers
    if (total > 11) {
      starting.sort((a, b) => b.predicted_points - a.predicted_points)
      while (starting.length > 11) {
        // Remove lowest scorer but maintain minimum formation (1 GK, 3 DEF, 1 FWD)
        for (let i = starting.length - 1; i >= 0; i--) {
          const p = starting[i]
          const samePos = starting.filter((s) => s.element_type === p.element_type).length
          const minRequired =
            p.element_type === 1 ? 1 : p.element_type === 2 ? 3 : p.element_type === 4 ? 1 : 0
          if (samePos > minRequired) {
            starting.splice(i, 1)
            break
          }
        }
      }
    }

    // Find captain (highest predicted points in starting)
    let maxPts = -1
    let captainId: number | null = null
    for (const p of starting) {
      if (p.predicted_points > maxPts) {
        maxPts = p.predicted_points
        captainId = p.player_id
      }
    }

    // Separate starters and bench, sort bench with GK first
    const startingIds = new Set(starting.map((p) => p.player_id))
    const starters = squadWithData.filter((p) => startingIds.has(p.player_id))
    const bench = squadWithData
      .filter((p) => !startingIds.has(p.player_id))
      .sort((a, b) => {
        // GK (element_type 1) always first on bench
        if (a.element_type === 1 && b.element_type !== 1) return -1
        if (a.element_type !== 1 && b.element_type === 1) return 1
        // Then by predicted points desc
        return b.predicted_points - a.predicted_points
      })

    // Assign positions: starters 1-11 by element_type, bench 12-15 in sorted order
    starters.sort((a, b) => a.element_type - b.element_type)

    let pos = 1
    const result = [
      ...starters.map((p) => ({
        ...p,
        position: pos++,
        is_captain: p.player_id === captainId,
        multiplier: p.player_id === captainId ? 2 : 1,
      })),
      ...bench.map((p) => ({
        ...p,
        position: pos++,
        is_captain: false,
        multiplier: 1,
      })),
    ]

    return result
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

            {/* Gameweek Selector */}
            <BroadcastCard title="Select Gameweek" animationDelay={200}>
              <div className="flex gap-2 flex-wrap">
                {predictionsRange?.gameweeks.map((gw, idx) => {
                  const pts = squadPredictions?.byGw[gw] ?? 0
                  const isFirst = idx === 0
                  const isSelected = gw === selectedGameweek
                  return (
                    <button
                      key={gw}
                      onClick={() => setSelectedGameweek(gw)}
                      className={`
                        px-4 py-3 rounded-lg animate-fade-in-up opacity-0 transition-all
                        ${
                          isSelected
                            ? 'bg-fpl-green/20 border-2 border-fpl-green ring-2 ring-fpl-green/30'
                            : isFirst && hitsCost > 0
                              ? 'bg-destructive/20 border border-destructive/30 hover:bg-destructive/30'
                              : 'bg-surface-elevated hover:bg-surface-hover border border-transparent'
                        }
                      `}
                      style={{ animationDelay: `${250 + idx * 50}ms` }}
                    >
                      <div
                        className={`text-xs font-display uppercase ${isSelected ? 'text-fpl-green' : 'text-foreground-muted'}`}
                      >
                        GW{gw}
                        {isFirst && hitsCost > 0 && ` (-${hitsCost})`}
                      </div>
                      <div
                        className={`text-xl font-mono font-bold ${isSelected ? 'text-fpl-green' : 'text-foreground'}`}
                      >
                        {pts.toFixed(1)}
                      </div>
                    </button>
                  )
                })}
              </div>
              <div className="mt-3 pt-3 border-t border-border/50 flex items-center justify-between">
                <span className="text-xs text-foreground-muted">
                  Total ({predictionsRange?.gameweeks.length ?? 6} GWs)
                </span>
                <span className="font-mono font-bold text-fpl-green text-lg">
                  {squadPredictions?.total.toFixed(1) ?? '...'} pts
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
                Click a player to transfer them out. Hover and click ✎ to edit expected minutes.
                Points shown are for GW{selectedGameweek ?? '?'}.
              </p>
              {formationPlayers.length > 0 ? (
                <FormationPitch
                  players={formationPlayers}
                  teams={teamsRecord}
                  editable
                  xMinsOverrides={xMinsOverrides}
                  onXMinsChange={handleXMinsChange}
                  transferMode
                  selectedForTransfer={selectedForTransferOut}
                  newTransferIds={stagedTransfers.map((t) => t.in)}
                  onPlayerClick={handleSelectForTransferOut}
                />
              ) : (
                <div className="text-center text-foreground-muted py-8">Loading squad...</div>
              )}
            </BroadcastCard>
          </div>

          {/* Sidebar - Transfers & Replacements */}
          <div className="space-y-6">
            {/* Staged Transfers */}
            {stagedTransfers.length > 0 && (
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
                          <span className="text-foreground-dim">→</span>
                          <span className="text-fpl-green">{transfer.inName}</span>
                        </div>
                        <div className="flex items-center justify-between mt-1 text-xs text-foreground-muted">
                          <span>
                            £{transfer.outPrice.toFixed(1)}m → £{transfer.inPrice.toFixed(1)}m
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

            {/* Replacement Picker */}
            {selectedOutPlayer && (
              <BroadcastCard
                title={`Replace ${selectedOutPlayer.web_name}`}
                accentColor="green"
                animationDelay={0}
              >
                <div className="space-y-3">
                  <div className="text-xs text-foreground-muted">
                    Budget: £{(selectedOutPlayer.now_cost / 10 + effectiveBank).toFixed(1)}m
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
                              {teamsMap.get(player.team)} · £{(player.now_cost / 10).toFixed(1)}m
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

            {/* Quick Tips when no player selected */}
            {!selectedForTransferOut && stagedTransfers.length === 0 && (
              <BroadcastCard title="How to Use" animationDelay={400}>
                <ul className="text-sm text-foreground-muted space-y-2">
                  <li>• Click any player in your squad to select them for transfer out</li>
                  <li>• Browse replacements sorted by predicted points</li>
                  <li>• See updated projections instantly</li>
                  <li>• Hits are deducted from GW1 predictions</li>
                </ul>
              </BroadcastCard>
            )}
          </div>
        </div>
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
