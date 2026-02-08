import { useState, useMemo, useEffect, useRef } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { usePlannerOptimize } from '../hooks/usePlannerOptimize'
import { usePredictionsRange } from '../hooks/usePredictionsRange'
import type {
  ChipPlan,
  PlayerMultiWeekPrediction,
  CaptainCandidate,
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

interface SavedPlan {
  id: string
  name: string
  transfers: FixedTransfer[]
  score: number
  scoreVsHold: number
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
  const [freeTransfers, setFreeTransfers] = useState<number | null>(null)
  const chipPlan: ChipPlan = {}
  const [xMinsOverrides, setXMinsOverrides] = useState<Record<number, number>>({})
  const [selectedGameweek, setSelectedGameweek] = useState<number | null>(null)

  // Path solver state
  const [selectedPathIndex, setSelectedPathIndex] = useState<number | null>(null)
  const [ftValue, setFtValue] = useState(1.5)
  const [solverDepth, setSolverDepth] = useState<SolverDepth>('standard')

  // User transfers (replaces old fixedTransfers concept)
  const [userTransfers, setUserTransfers] = useState<FixedTransfer[]>([])

  // Solve state — controls whether the solver runs
  const [solveRequested, setSolveRequested] = useState(false)
  const [solveTransfers, setSolveTransfers] = useState<FixedTransfer[]>([])

  // Transfer planning state
  const [selectedForTransferOut, setSelectedForTransferOut] = useState<number | null>(null)
  const [replacementSearch, setReplacementSearch] = useState('')

  // Saved plans
  const [savedPlans, setSavedPlans] = useState<SavedPlan[]>(() => {
    const stored = localStorage.getItem('fpl_saved_plans')
    return stored ? JSON.parse(stored) : []
  })
  useEffect(() => {
    localStorage.setItem('fpl_saved_plans', JSON.stringify(savedPlans))
  }, [savedPlans])

  // UI state
  const [showHelp, setShowHelp] = useState(false)
  const [isExplorerExpanded, setIsExplorerExpanded] = useState(false)

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

  // Squad query — always active, skips solver
  const {
    data: squadData,
    isLoading: isLoadingSquad,
    error: squadError,
  } = usePlannerOptimize(
    managerId,
    freeTransfers,
    chipPlan,
    debouncedXMins,
    userTransfers,
    ftValue,
    solverDepth,
    true // skipSolve
  )

  // Solve query — only when requested
  const {
    data: solveData,
    isLoading: isSolving,
    isFetching: isFetchingSolve,
  } = usePlannerOptimize(
    solveRequested ? managerId : null, // null disables the query
    freeTransfers,
    chipPlan,
    debouncedXMins,
    solveTransfers,
    ftValue,
    solverDepth,
    false // run solver
  )

  // Stale detection — user changed transfers after solving
  const isStale = solveRequested && JSON.stringify(userTransfers) !== JSON.stringify(solveTransfers)

  // Merge squad + solve data for display
  const optimizeData = squadData
  const paths = solveData?.paths ?? []

  // Get predictions for multiple gameweeks
  const { data: predictionsRange } = usePredictionsRange()

  // Get top 10k effective ownership for player explorer
  const { data: samplesData } = useLiveSamples(predictionsRange?.current_gameweek ?? null)
  const top10kEO = samplesData?.samples?.top_10k?.effective_ownership

  // Initialize selectedGameweek when data loads - use current_gameweek from API
  useEffect(() => {
    if (predictionsRange && selectedGameweek === null) {
      const initialGw = predictionsRange.current_gameweek || predictionsRange.gameweeks?.[0]
      if (initialGw) {
        setSelectedGameweek(initialGw)
      }
    }
  }, [predictionsRange, selectedGameweek])

  // Auto-populate free transfers from FPL API when data first loads
  useEffect(() => {
    if (optimizeData?.current_squad?.api_free_transfers && freeTransfers === null) {
      setFreeTransfers(optimizeData.current_squad.api_free_transfers)
    }
  }, [optimizeData?.current_squad?.api_free_transfers, freeTransfers])

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
  const selectedPath = useMemo(() => {
    if (selectedPathIndex === null || !paths.length) return null
    return paths[selectedPathIndex] ?? null
  }, [selectedPathIndex, paths])

  // GW-aware effective squad: apply transfers up to and including selectedGameweek
  const effectiveSquad = useMemo(() => {
    if (!optimizeData?.current_squad?.player_ids) return []
    let squad = [...optimizeData.current_squad.player_ids]
    const gws = predictionsRange?.gameweeks ?? []
    for (const gw of gws) {
      if (selectedGameweek !== null && gw > selectedGameweek) break
      const gwTransfers = userTransfers.filter((t) => t.gameweek === gw)
      for (const ft of gwTransfers) {
        squad = squad.filter((id) => id !== ft.out)
        squad.push(ft.in)
      }
    }
    return squad
  }, [
    optimizeData?.current_squad?.player_ids,
    userTransfers,
    selectedGameweek,
    predictionsRange?.gameweeks,
  ])

  // GW-aware effective bank: apply transfer costs up to and including selectedGameweek
  const effectiveBank = useMemo(() => {
    if (!optimizeData?.current_squad?.bank) return 0
    let bank = optimizeData.current_squad.bank
    const gws = predictionsRange?.gameweeks ?? []
    for (const gw of gws) {
      if (selectedGameweek !== null && gw > selectedGameweek) break
      const gwTransfers = userTransfers.filter((t) => t.gameweek === gw)
      for (const ft of gwTransfers) {
        const outPlayer = playersData?.players.find((p) => p.id === ft.out)
        const inPlayer = playersData?.players.find((p) => p.id === ft.in)
        if (outPlayer && inPlayer) {
          bank += outPlayer.now_cost / 10 - inPlayer.now_cost / 10
        }
      }
    }
    return Math.round(bank * 10) / 10
  }, [
    optimizeData?.current_squad?.bank,
    userTransfers,
    selectedGameweek,
    predictionsRange?.gameweeks,
    playersData?.players,
  ])

  // Calculate per-GW FT state with rollover logic
  const effectiveFt = freeTransfers ?? optimizeData?.current_squad?.api_free_transfers ?? 1
  const ftByGameweek = useMemo(() => {
    if (!predictionsRange?.gameweeks) return {} as Record<number, number>

    // Group user transfers by GW
    const transfersByGw: Record<number, number> = {}
    for (const t of userTransfers) {
      transfersByGw[t.gameweek] = (transfersByGw[t.gameweek] ?? 0) + 1
    }

    const result: Record<number, number> = {}
    let available = effectiveFt

    for (const gw of predictionsRange.gameweeks) {
      result[gw] = available
      const transfers = transfersByGw[gw] ?? 0

      if (transfers === 0) {
        available = Math.min(5, available + 1) // bank
      } else {
        available = Math.max(1, available - transfers + 1) // consume + 1 new FT
      }
    }
    return result
  }, [predictionsRange?.gameweeks, effectiveFt, userTransfers])

  // Hits for the first GW (used by squad predictions for hit cost deduction)
  const firstGw = predictionsRange?.gameweeks[0]
  const firstGwTransfers = firstGw ? userTransfers.filter((f) => f.gameweek === firstGw).length : 0
  const hitsCount = firstGw
    ? Math.max(0, firstGwTransfers - (ftByGameweek[firstGw] ?? effectiveFt))
    : 0
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
        if (p.position !== position) return false
        if (effectiveSquad.includes(p.player_id)) return false
        if (p.now_cost / 10 > maxBudget) return false
        const currentTeamCount = teamCounts.get(p.team) || 0
        const adjustedCount =
          p.team === selectedOutPlayer.team ? currentTeamCount - 1 : currentTeamCount
        if (adjustedCount >= 3) return false
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
      setSelectedForTransferOut(null)
      setSelectedPathIndex(null)
      setUserTransfers([])
      setSolveRequested(false)
      setSolveTransfers([])
      setFreeTransfers(null)
    }
  }

  const handleSelectForTransferOut = (playerId: number) => {
    if (selectedForTransferOut === playerId) {
      setSelectedForTransferOut(null)
      setReplacementSearch('')
    } else {
      setSelectedForTransferOut(playerId)
      setReplacementSearch('')
    }
  }

  const handleSelectReplacement = (replacement: PlayerMultiWeekPrediction) => {
    if (!selectedOutPlayer || selectedGameweek === null) return

    let newTransfers = [...userTransfers]

    // If the outgoing player was brought in by an existing transfer for this GW,
    // update that transfer instead of adding a new one
    const existingIdx = newTransfers.findIndex(
      (ft) => ft.in === selectedOutPlayer.id && ft.gameweek === selectedGameweek
    )
    if (existingIdx !== -1) {
      newTransfers = newTransfers.map((ft, i) =>
        i === existingIdx ? { ...ft, in: replacement.player_id } : ft
      )
    } else {
      newTransfers.push({
        gameweek: selectedGameweek,
        out: selectedOutPlayer.id,
        in: replacement.player_id,
      })
    }

    setUserTransfers(newTransfers)
    setSelectedPathIndex(null)
    setSelectedForTransferOut(null)
    setReplacementSearch('')
  }

  const handleReset = () => {
    setSelectedForTransferOut(null)
    setReplacementSearch('')
    setSelectedPathIndex(null)
    setUserTransfers([])
    setSolveRequested(false)
    setSolveTransfers([])
  }

  const handleFindPlans = () => {
    setSolveTransfers([...userTransfers])
    setSolveRequested(true)
  }

  const handleSelectPlan = (idx: number) => {
    if (selectedPathIndex === idx) {
      setSelectedPathIndex(null)
      return
    }
    const path = paths[idx]
    if (!path) return

    // Auto-apply ALL plan transfers across ALL GWs
    const planTransfers: FixedTransfer[] = []
    for (const [gwStr, gwData] of Object.entries(path.transfers_by_gw)) {
      for (const move of gwData.moves) {
        planTransfers.push({ gameweek: Number(gwStr), out: move.out_id, in: move.in_id })
      }
    }
    setUserTransfers(planTransfers)
    setSelectedPathIndex(idx)
    setSelectedForTransferOut(null)
  }

  const handleSavePlan = () => {
    if (selectedPathIndex === null || !paths[selectedPathIndex]) return
    const path = paths[selectedPathIndex]
    const plan: SavedPlan = {
      id: `plan-${Date.now()}`,
      name: `Plan ${savedPlans.length + 1}`,
      transfers: [...userTransfers],
      score: path.total_score,
      scoreVsHold: path.score_vs_hold,
    }
    setSavedPlans((prev) => [...prev, plan])
  }

  const handleLoadSavedPlan = (plan: SavedPlan) => {
    setUserTransfers(plan.transfers)
    setSelectedPathIndex(null)
    setSolveRequested(false)
  }

  const handleDeleteSavedPlan = (planId: string) => {
    setSavedPlans((prev) => prev.filter((p) => p.id !== planId))
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

  // Calculate predicted points for effective squad (optimal starting XI only)
  const squadPredictions = useMemo(() => {
    if (!predictionsRange?.gameweeks || !playerPredictionsMap.size || !playersData?.players)
      return null

    const byGw: Record<number, number> = {}
    let total = 0

    for (const gw of predictionsRange.gameweeks) {
      // Build GW-aware squad for this GW
      let gwSquad = [...(optimizeData?.current_squad?.player_ids ?? [])]
      for (const gwCheck of predictionsRange.gameweeks) {
        if (gwCheck > gw) break
        const gwTransfers = userTransfers.filter((t) => t.gameweek === gwCheck)
        for (const ft of gwTransfers) {
          gwSquad = gwSquad.filter((id) => id !== ft.out)
          gwSquad.push(ft.in)
        }
      }

      const squadWithData = gwSquad
        .map((playerId) => {
          const player = playersData.players.find((p) => p.id === playerId)
          const pred = playerPredictionsMap.get(playerId)
          if (!player) return null
          return {
            player_id: playerId,
            web_name: player.web_name,
            element_type: player.element_type,
            team: player.team,
            predicted_points: pred?.predictions[gw] ?? 0,
          }
        })
        .filter((p): p is NonNullable<typeof p> => p !== null)

      const formation = buildFormation(squadWithData)
      let gwTotal = 0
      for (const fp of formation) {
        if (fp.position <= 11) {
          gwTotal += fp.predicted_points * fp.multiplier
        }
      }

      // Subtract hits from first gameweek
      if (gw === predictionsRange.gameweeks[0]) {
        gwTotal -= hitsCost
      }

      byGw[gw] = Math.round(gwTotal * 10) / 10
      total += byGw[gw]
    }

    return { byGw, total: Math.round(total * 10) / 10 }
  }, [
    optimizeData?.current_squad?.player_ids,
    userTransfers,
    predictionsRange,
    playerPredictionsMap,
    playersData?.players,
    hitsCost,
  ])

  // IDs of new transfers visible in the current GW (for highlighting on pitch)
  const newTransferIds = useMemo(() => {
    if (!optimizeData?.current_squad?.player_ids) return []
    const originalSquad = new Set(optimizeData.current_squad.player_ids)
    return effectiveSquad.filter((id) => !originalSquad.has(id))
  }, [optimizeData?.current_squad?.player_ids, effectiveSquad])

  // Group user transfers by GW for sidebar display
  const transfersByGw = useMemo(() => {
    const grouped: Record<number, FixedTransfer[]> = {}
    for (const t of userTransfers) {
      if (!grouped[t.gameweek]) grouped[t.gameweek] = []
      grouped[t.gameweek].push(t)
    }
    return grouped
  }, [userTransfers])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="animate-fade-in-up flex items-start justify-between">
        <div>
          <h2 className="font-display text-2xl font-bold tracking-wider uppercase text-foreground mb-2">
            Transfer Planner
          </h2>
          <p className="font-body text-foreground-muted text-sm mb-4">
            Build your squad, then press Find Plans to see optimized suggestions.
          </p>
        </div>
        <button
          onClick={() => setShowHelp(true)}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-display uppercase tracking-wider text-foreground-muted hover:text-foreground hover:bg-surface-hover transition-colors"
        >
          <span className="w-5 h-5 rounded-full border border-current flex items-center justify-center text-[10px] font-bold">
            ?
          </span>
          Help
        </button>
      </div>

      {/* Help Modal */}
      {showHelp && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          onClick={() => setShowHelp(false)}
        >
          <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" />
          <div
            className="relative bg-surface border border-border rounded-lg max-w-lg w-full max-h-[80vh] overflow-y-auto shadow-2xl animate-fade-in-up"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="p-5 border-b border-border bg-gradient-to-r from-fpl-purple/10 to-transparent">
              <h3 className="font-display text-lg font-bold tracking-wider uppercase text-foreground">
                How to Use the Planner
              </h3>
            </div>
            <div className="p-5 space-y-5">
              <div>
                <h4 className="font-display text-sm uppercase tracking-wider text-fpl-green mb-2">
                  1. Make Transfers
                </h4>
                <p className="text-sm text-foreground-muted">
                  Click any player on the pitch to transfer them out and pick a replacement. Your
                  transfers appear in the sidebar.
                </p>
              </div>
              <div>
                <h4 className="font-display text-sm uppercase tracking-wider text-fpl-green mb-2">
                  2. Find Plans
                </h4>
                <p className="text-sm text-foreground-muted">
                  Press "Find Plans" to run the optimizer. It finds multi-gameweek transfer paths
                  that maximize points, respecting any transfers you've already made.
                </p>
              </div>
              <div>
                <h4 className="font-display text-sm uppercase tracking-wider text-fpl-green mb-2">
                  3. Select & Save
                </h4>
                <p className="text-sm text-foreground-muted">
                  Select a plan to auto-apply its transfers to your squad. Save plans you like for
                  later comparison.
                </p>
              </div>
              <div>
                <h4 className="font-display text-sm uppercase tracking-wider text-fpl-green mb-2">
                  Solver Controls
                </h4>
                <p className="text-sm text-foreground-muted">
                  <span className="text-foreground font-medium">FT Value</span> controls hit
                  aversion — higher values make the solver prefer rolling transfers.{' '}
                  <span className="text-foreground font-medium">Depth</span> controls search
                  thoroughness.
                </p>
              </div>
            </div>
            <div className="p-5 border-t border-border">
              <button onClick={() => setShowHelp(false)} className="btn-primary w-full">
                Got It
              </button>
            </div>
          </div>
        </div>
      )}

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
            value={freeTransfers ?? effectiveFt}
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

        <div className="flex items-end gap-2">
          <button
            onClick={handleFindPlans}
            disabled={isSolving || !managerId}
            className={`btn-primary flex-1 relative ${isStale ? 'ring-2 ring-yellow-400/50' : ''}`}
          >
            {isSolving ? (
              <span className="inline-flex items-center gap-2">
                <span className="w-3 h-3 rounded-full border-2 border-white/30 border-t-white animate-spin" />
                Solving...
              </span>
            ) : isStale ? (
              'Re-solve'
            ) : (
              'Find Plans'
            )}
          </button>
          {userTransfers.length > 0 && (
            <button onClick={handleReset} className="btn-secondary">
              Reset
            </button>
          )}
        </div>

        {/* Solver Controls */}
        <div className="space-y-2">
          <label className="font-display text-xs uppercase tracking-wider text-foreground-muted">
            Solver Settings
          </label>
          <div className="flex items-center gap-2">
            <span className="text-xs text-foreground-muted whitespace-nowrap">FT</span>
            <input
              type="range"
              min="0"
              max="5"
              step="0.5"
              value={ftValue}
              onChange={(e) => setFtValue(parseFloat(e.target.value))}
              className="flex-1 accent-fpl-purple"
            />
            <span className="font-mono text-xs text-foreground w-7 text-right">
              {ftValue.toFixed(1)}
            </span>
          </div>
          <div className="flex gap-1">
            {(['quick', 'standard', 'deep'] as const).map((d) => (
              <button
                key={d}
                onClick={() => setSolverDepth(d)}
                className={`px-2 py-0.5 rounded text-[10px] font-display uppercase tracking-wider transition-colors ${
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
      </div>

      {squadError && (
        <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
          {squadError.message || 'Failed to load optimization data'}
        </div>
      )}

      {isLoadingSquad && managerId && (
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
                value={`\u00A3${optimizeData.current_squad.squad_value.toFixed(1)}m`}
                animationDelay={0}
              />
              <StatPanel
                label={selectedGameweek !== null ? `Bank (GW${selectedGameweek})` : 'Bank'}
                value={`\u00A3${effectiveBank.toFixed(1)}m`}
                animationDelay={50}
                highlight={effectiveBank !== optimizeData.current_squad.bank}
              />
              <StatPanel
                label="Transfers"
                value={(() => {
                  if (selectedGameweek !== null) {
                    const available = ftByGameweek[selectedGameweek] ?? effectiveFt
                    const used = userTransfers.filter((f) => f.gameweek === selectedGameweek).length
                    const remaining = Math.max(0, available - used)
                    const hits = Math.max(0, used - available)
                    const hitPts = hits * HIT_COST
                    return `${remaining} FT${hitPts > 0 ? ` (-${hitPts})` : ''}`
                  }
                  return `${effectiveFt} FT`
                })()}
                animationDelay={100}
                highlight={(() => {
                  if (selectedGameweek !== null) {
                    const available = ftByGameweek[selectedGameweek] ?? effectiveFt
                    const used = userTransfers.filter((f) => f.gameweek === selectedGameweek).length
                    return used > available
                  }
                  return false
                })()}
              />
              <StatPanel
                label={`Projected (${predictionsRange?.gameweeks.length ?? 6} GWs)`}
                value={`${selectedPath ? selectedPath.total_score.toFixed(1) : (squadPredictions?.total ?? '...')} pts`}
                highlight
                animationDelay={150}
              />
            </StatPanelGrid>

            {/* Recommended Plans */}
            {paths.length > 0 && (
              <BroadcastCard
                title="Recommended Plans"
                accentColor="purple"
                animationDelay={175}
                headerAction={
                  isFetchingSolve && !isSolving ? (
                    <span className="inline-flex items-center gap-1.5 text-xs font-display uppercase tracking-wider text-fpl-purple">
                      <span className="w-2 h-2 rounded-full bg-fpl-purple animate-pulse" />
                      Recalculating
                    </span>
                  ) : undefined
                }
              >
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                  {paths.map((path, idx) => {
                    const isSelected = selectedPathIndex === idx
                    const horizon = optimizeData.planning_horizon
                    return (
                      <button
                        key={path.id}
                        onClick={() => handleSelectPlan(idx)}
                        className={`p-3 rounded-lg text-left transition-all animate-fade-in-up opacity-0 ${
                          isSelected
                            ? 'bg-fpl-purple/20 border-2 border-fpl-purple ring-2 ring-fpl-purple/30'
                            : 'bg-surface-elevated hover:bg-surface-hover border border-transparent'
                        }`}
                        style={{ animationDelay: `${200 + idx * 50}ms` }}
                      >
                        <div className="flex items-center justify-between mb-2">
                          <span className="font-display text-xs uppercase tracking-wider text-foreground-muted">
                            Plan {path.id}
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
                                  <span className="text-foreground-dim">Roll</span>
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
                  const gwUserTransfers = userTransfers.filter((t) => t.gameweek === gw)
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
                                : gwUserTransfers.length > 0
                                  ? 'bg-fpl-green/10 border border-fpl-green/20 hover:bg-fpl-green/20'
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
                            ? 'Roll'
                            : `${pathGw.moves.length} move${pathGw.moves.length > 1 ? 's' : ''}`}
                        </div>
                      )}
                      {!selectedPath && gwUserTransfers.length > 0 && (
                        <div className="text-[10px] text-fpl-green mt-0.5">
                          {gwUserTransfers.length} transfer
                          {gwUserTransfers.length > 1 ? 's' : ''}
                        </div>
                      )}
                    </button>
                  )
                })}
              </div>
              <div className="mt-3 pt-3 border-t border-border/50 flex items-center justify-between">
                <span className="text-xs text-foreground-muted">
                  Total ({predictionsRange?.gameweeks.length ?? 6} GWs)
                  {selectedPath && ' \u2014 Plan ' + selectedPath.id}
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
              title={`Your Squad \u2014 GW${selectedGameweek ?? '?'} Predictions`}
              accentColor="green"
              animationDelay={300}
            >
              <p className="text-xs text-foreground-muted mb-4">
                Click a player to make a transfer. Press Find Plans to see optimized suggestions.
                {selectedGameweek !== null && ` Showing squad as of GW${selectedGameweek}.`}
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
                  newTransferIds={newTransferIds}
                  onPlayerClick={handleSelectForTransferOut}
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

          {/* Sidebar */}
          <div className="space-y-6">
            {/* Your Transfers */}
            {userTransfers.length > 0 && (
              <BroadcastCard title="Your Transfers" accentColor="green" animationDelay={200}>
                <div className="space-y-3">
                  {Object.entries(transfersByGw)
                    .sort(([a], [b]) => Number(a) - Number(b))
                    .map(([gwStr, transfers]) => (
                      <div key={gwStr}>
                        <div className="font-display text-xs uppercase tracking-wider text-foreground-muted mb-1.5">
                          GW{gwStr}
                        </div>
                        <div className="space-y-1.5">
                          {transfers.map((ft, idx) => {
                            const outPlayer = playersData?.players.find((p) => p.id === ft.out)
                            const inPlayer = playersData?.players.find((p) => p.id === ft.in)
                            return (
                              <div
                                key={idx}
                                className="p-2 bg-surface-elevated rounded-lg flex items-center justify-between animate-fade-in-up opacity-0"
                                style={{ animationDelay: `${idx * 50}ms` }}
                              >
                                <div className="flex items-center gap-2 text-sm min-w-0">
                                  <span className="text-destructive truncate">
                                    {outPlayer?.web_name ?? `#${ft.out}`}
                                  </span>
                                  <span className="text-foreground-dim">{'\u2192'}</span>
                                  <span className="text-fpl-green truncate">
                                    {inPlayer?.web_name ?? `#${ft.in}`}
                                  </span>
                                </div>
                                <button
                                  onClick={() => {
                                    setUserTransfers((prev) =>
                                      prev.filter(
                                        (t) =>
                                          !(
                                            t.gameweek === ft.gameweek &&
                                            t.out === ft.out &&
                                            t.in === ft.in
                                          )
                                      )
                                    )
                                    setSelectedPathIndex(null)
                                  }}
                                  className="text-xs text-foreground-muted hover:text-destructive ml-2 shrink-0"
                                >
                                  {'\u2715'}
                                </button>
                              </div>
                            )
                          })}
                        </div>
                      </div>
                    ))}
                  {selectedPathIndex !== null && (
                    <button onClick={handleSavePlan} className="btn-secondary w-full">
                      Save Plan
                    </button>
                  )}
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

            {/* Saved Plans */}
            {savedPlans.length > 0 && (
              <BroadcastCard title="Saved Plans" accentColor="purple" animationDelay={250}>
                <div className="space-y-2">
                  {savedPlans.map((plan) => (
                    <div
                      key={plan.id}
                      className="p-3 bg-surface-elevated rounded-lg animate-fade-in-up opacity-0"
                    >
                      <div className="flex items-center justify-between mb-1">
                        <span className="font-display text-xs uppercase tracking-wider text-foreground">
                          {plan.name}
                        </span>
                        <span
                          className={`font-mono text-sm font-bold ${plan.scoreVsHold > 0 ? 'text-fpl-green' : 'text-foreground'}`}
                        >
                          {plan.scoreVsHold > 0 ? '+' : ''}
                          {plan.scoreVsHold.toFixed(1)}
                        </span>
                      </div>
                      <div className="text-xs text-foreground-muted mb-2">
                        {plan.transfers.length} transfer{plan.transfers.length !== 1 ? 's' : ''} ·{' '}
                        {plan.score.toFixed(1)} pts
                      </div>
                      <div className="flex gap-2">
                        <button
                          onClick={() => handleLoadSavedPlan(plan)}
                          className="text-xs text-fpl-green hover:text-fpl-green/80 transition-colors"
                        >
                          Load
                        </button>
                        <button
                          onClick={() => handleDeleteSavedPlan(plan.id)}
                          className="text-xs text-foreground-muted hover:text-destructive transition-colors"
                        >
                          Delete
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </BroadcastCard>
            )}
          </div>
        </div>
      )}

      {/* Player Explorer - collapsible */}
      {predictionsRange && (
        <div className="animate-fade-in-up">
          <button
            onClick={() => setIsExplorerExpanded((prev) => !prev)}
            className="w-full flex items-center justify-between p-4 bg-surface-elevated rounded-lg hover:bg-surface-hover transition-colors"
          >
            <div className="flex items-center gap-3">
              <h3 className="font-display text-sm font-bold tracking-wider uppercase text-foreground">
                Player Explorer
              </h3>
              <span className="text-xs text-foreground-muted font-mono">
                {predictionsRange.players.length} players
              </span>
            </div>
            <span
              className={`text-foreground-muted transition-transform ${isExplorerExpanded ? 'rotate-180' : ''}`}
            >
              {'\u25BC'}
            </span>
          </button>
          {isExplorerExpanded && (
            <div className="mt-2">
              <PlayerExplorer
                players={predictionsRange.players}
                gameweeks={predictionsRange.gameweeks}
                teamsMap={teamsMap}
                effectiveOwnership={top10kEO}
                xMinsOverrides={xMinsOverrides}
                onXMinsChange={handleXMinsChange}
                onResetXMins={() => setXMinsOverrides({})}
              />
            </div>
          )}
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
