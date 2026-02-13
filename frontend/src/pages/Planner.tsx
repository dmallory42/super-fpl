import { useState, useMemo, useEffect, useRef } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { usePlannerOptimize } from '../hooks/usePlannerOptimize'
import { usePredictionsRange } from '../hooks/usePredictionsRange'
import type {
  ChipPlan,
  ChipMode,
  PlayerMultiWeekPrediction,
  FixedTransfer,
  PathGameweek,
  PlannerConstraints,
  PlannerObjectiveMode,
  SolverDepth,
  XMinsOverrides,
  FixtureOpponent,
} from '../api/client'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { EmptyState, ChartIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonCard } from '../components/ui/SkeletonLoader'
import { FormationPitch } from '../components/live/FormationPitch'
import { useLiveSamples } from '../hooks/useLiveSamples'
import { PlayerExplorer } from '../components/planner/PlayerExplorer'
import { PlayerDetailPanel } from '../components/planner/PlayerDetailPanel'
import { buildFormation } from '../lib/formation'
import { scalePoints } from '../lib/predictions'

const HIT_COST = 4
const CHIP_LABELS: Record<keyof ChipPlan, string> = {
  wildcard: 'Wildcard',
  free_hit: 'Free Hit',
  bench_boost: 'Bench Boost',
  triple_captain: 'Triple Captain',
}
const CHIP_SHORT_LABELS: Record<keyof ChipPlan, string> = {
  wildcard: 'WC',
  free_hit: 'FH',
  bench_boost: 'BB',
  triple_captain: 'TC',
}
const OBJECTIVE_LABELS: Record<PlannerObjectiveMode, string> = {
  expected: 'Expected',
  floor: 'Floor',
  ceiling: 'Ceiling',
}
const OBJECTIVE_CONTEXT: Record<PlannerObjectiveMode, string> = {
  expected: 'Balances upside and downside around median projection.',
  floor: 'Prioritizes safer minutes and lower downside outcomes.',
  ceiling: 'Prioritizes upside and high-variance outcomes.',
}

function formatFixtures(fixtures: FixtureOpponent[]): string {
  return fixtures.map((f) => (f.is_home ? f.opponent : f.opponent.toLowerCase())).join(', ')
}

interface SavedPlan {
  id: string
  name: string
  transfers: FixedTransfer[]
  score: number
  scoreVsHold: number
}

interface ConstraintInputState {
  lockIds: string
  avoidIds: string
  maxHits: string
  chipWindows: Record<keyof ChipPlan, string>
}

interface GameweekRationaleRow {
  gw: number
  actionLabel: string
  expectedGain: number | null
  hitCost: number
  riskTradeoff: string
  objectiveMode: PlannerObjectiveMode
  objectiveContext: string
  chipLabel: string | null
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

function getInitialConstraintInputs(): ConstraintInputState {
  const empty: ConstraintInputState = {
    lockIds: '',
    avoidIds: '',
    maxHits: '',
    chipWindows: {
      wildcard: '',
      free_hit: '',
      bench_boost: '',
      triple_captain: '',
    },
  }

  const parseConstraints = (raw: string | null): PlannerConstraints | null => {
    if (!raw) return null
    try {
      return JSON.parse(raw) as PlannerConstraints
    } catch {
      return null
    }
  }

  const params = new URLSearchParams(window.location.search)
  const fromUrl = parseConstraints(params.get('constraints'))
  const fromStorage = parseConstraints(localStorage.getItem('fpl_planner_constraints'))
  const source = fromUrl ?? fromStorage
  if (!source) {
    return empty
  }

  const chipWindows = { ...empty.chipWindows }
  for (const chip of Object.keys(chipWindows) as (keyof ChipPlan)[]) {
    const weeks = source.chip_windows?.[chip]
    chipWindows[chip] = Array.isArray(weeks) ? weeks.join(',') : ''
  }

  return {
    lockIds: Array.isArray(source.lock_ids) ? source.lock_ids.join(',') : '',
    avoidIds: Array.isArray(source.avoid_ids) ? source.avoid_ids.join(',') : '',
    maxHits:
      source.max_hits === null || source.max_hits === undefined ? '' : String(source.max_hits),
    chipWindows,
  }
}

export function Planner() {
  const initial = getInitialManagerId()
  const [managerId, setManagerId] = useState<number | null>(initial.id)
  const [managerInput, setManagerInput] = useState(initial.input)
  const [freeTransfers, setFreeTransfers] = useState<number | null>(null)
  const [chipPlan, setChipPlan] = useState<ChipPlan>({})
  const [chipMode, setChipMode] = useState<ChipMode>('locked')
  const [chipCompare, setChipCompare] = useState(true)
  const [xMinsOverrides, setXMinsOverrides] = useState<XMinsOverrides>({})
  const [selectedGameweek, setSelectedGameweek] = useState<number | null>(null)

  // Path solver state
  const [selectedPathIndex, setSelectedPathIndex] = useState<number | null>(null)
  const [comparePlanAIndex, setComparePlanAIndex] = useState<number | null>(null)
  const [comparePlanBIndex, setComparePlanBIndex] = useState<number | null>(null)
  const [ftValue, setFtValue] = useState(1.5)
  const [solverDepth, setSolverDepth] = useState<SolverDepth>('standard')
  const [objectiveMode, setObjectiveMode] = useState<PlannerObjectiveMode>('expected')
  const initialConstraintInputs = getInitialConstraintInputs()
  const [lockIdsInput, setLockIdsInput] = useState(initialConstraintInputs.lockIds)
  const [avoidIdsInput, setAvoidIdsInput] = useState(initialConstraintInputs.avoidIds)
  const [maxHitsInput, setMaxHitsInput] = useState(initialConstraintInputs.maxHits)
  const [chipWindowInputs, setChipWindowInputs] = useState(initialConstraintInputs.chipWindows)

  // User transfers (replaces old fixedTransfers concept)
  const [userTransfers, setUserTransfers] = useState<FixedTransfer[]>([])

  // Solve state — controls whether the solver runs
  const [solveRequested, setSolveRequested] = useState(false)
  const [solveTransfers, setSolveTransfers] = useState<FixedTransfer[]>([])
  const [solveChipPlan, setSolveChipPlan] = useState<ChipPlan>({})
  const [solveChipMode, setSolveChipMode] = useState<ChipMode>('locked')
  const [solveChipCompare, setSolveChipCompare] = useState(true)
  const [solveFtValue, setSolveFtValue] = useState(1.5)
  const [solveDepth, setSolveDepth] = useState<SolverDepth>('standard')
  const [solveObjectiveMode, setSolveObjectiveMode] = useState<PlannerObjectiveMode>('expected')
  const [solveConstraints, setSolveConstraints] = useState<PlannerConstraints>({})
  const [showSolveLoader, setShowSolveLoader] = useState(false)

  // Player detail sidebar state
  const [selectedPlayer, setSelectedPlayer] = useState<number | null>(null)
  const [sidebarTab, setSidebarTab] = useState<'projections' | 'transfer'>('projections')
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
  const [debouncedXMins, setDebouncedXMins] = useState<XMinsOverrides>(xMinsOverrides)
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

  const parsedConstraints = useMemo(() => {
    const parseIdList = (raw: string): number[] => {
      const trimmed = raw.trim()
      if (!trimmed) return []
      return Array.from(
        new Set(
          trimmed
            .split(',')
            .map((part) => parseInt(part.trim(), 10))
            .filter((id) => Number.isFinite(id) && id > 0)
        )
      )
    }

    const parseGwList = (raw: string): number[] => {
      const trimmed = raw.trim()
      if (!trimmed) return []
      return Array.from(
        new Set(
          trimmed
            .split(',')
            .map((part) => parseInt(part.trim(), 10))
            .filter((gw) => Number.isFinite(gw) && gw > 0 && gw <= 38)
        )
      )
    }

    const lockIds = parseIdList(lockIdsInput)
    const avoidIds = parseIdList(avoidIdsInput)
    const overlap = lockIds.filter((id) => avoidIds.includes(id))

    const parsedMaxHits = maxHitsInput.trim() === '' ? null : Number(maxHitsInput.trim())
    const normalizedMaxHits =
      maxHitsInput.trim() === ''
        ? null
        : parsedMaxHits !== null && Number.isInteger(parsedMaxHits) && parsedMaxHits >= 0
          ? parsedMaxHits
          : null

    const chipWindows: PlannerConstraints['chip_windows'] = {}
    for (const chip of Object.keys(chipWindowInputs) as (keyof ChipPlan)[]) {
      const weeks = parseGwList(chipWindowInputs[chip])
      if (weeks.length > 0) {
        chipWindows[chip] = weeks
      }
    }

    const errors: string[] = []
    if (overlap.length > 0) {
      errors.push(`Lock and avoid overlap on IDs: ${overlap.join(', ')}`)
    }
    if (maxHitsInput.trim() !== '' && normalizedMaxHits === null) {
      errors.push('Max hits must be a non-negative integer')
    }

    const constraints: PlannerConstraints = {}
    if (lockIds.length > 0) constraints.lock_ids = lockIds
    if (avoidIds.length > 0) constraints.avoid_ids = avoidIds
    if (normalizedMaxHits !== null) constraints.max_hits = normalizedMaxHits
    if (Object.keys(chipWindows).length > 0) constraints.chip_windows = chipWindows

    return {
      constraints,
      errors,
      hasErrors: errors.length > 0,
    }
  }, [lockIdsInput, avoidIdsInput, maxHitsInput, chipWindowInputs])

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
    true, // skipSolve
    chipMode,
    objectiveMode,
    parsedConstraints.constraints,
    false
  )

  // Solve query — only when requested
  const {
    data: solveData,
    isFetching: isFetchingSolve,
    error: solveError,
  } = usePlannerOptimize(
    solveRequested ? managerId : null, // null disables the query
    freeTransfers,
    solveChipPlan,
    debouncedXMins,
    solveTransfers,
    solveFtValue,
    solveDepth,
    false, // run solver
    solveChipMode,
    solveObjectiveMode,
    solveConstraints,
    solveChipCompare
  )

  // Stale detection — user changed transfers after solving
  const isStale =
    solveRequested &&
    (JSON.stringify(userTransfers) !== JSON.stringify(solveTransfers) ||
      JSON.stringify(chipPlan) !== JSON.stringify(solveChipPlan) ||
      chipMode !== solveChipMode ||
      chipCompare !== solveChipCompare ||
      ftValue !== solveFtValue ||
      solverDepth !== solveDepth ||
      objectiveMode !== solveObjectiveMode ||
      JSON.stringify(parsedConstraints.constraints) !== JSON.stringify(solveConstraints))

  const isSolveActive = showSolveLoader || isFetchingSolve

  // Merge squad + solve data for display
  const optimizeData = squadData
  const paths = solveData?.paths ?? []
  const chipData = solveData ?? optimizeData
  const chipSuggestions = chipData?.chip_suggestions_ranked ?? {}
  const chipResolvedPlan = chipData?.resolved_chip_plan ?? chipData?.chip_plan ?? {}
  const comparison = solveData?.comparisons

  useEffect(() => {
    if (paths.length >= 2) {
      setComparePlanAIndex(0)
      setComparePlanBIndex(1)
      return
    }
    if (paths.length === 1) {
      setComparePlanAIndex(0)
      setComparePlanBIndex(null)
      return
    }
    setComparePlanAIndex(null)
    setComparePlanBIndex(null)
  }, [solveData, paths.length])

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

  const chipTimelineByGw = useMemo(() => {
    const timeline: Record<number, string> = {}
    const gws = predictionsRange?.gameweeks ?? []

    for (const gw of gws) {
      const pathChip = selectedPath?.transfers_by_gw?.[gw]?.chip_played as
        | keyof ChipPlan
        | undefined
      if (pathChip && CHIP_SHORT_LABELS[pathChip]) {
        timeline[gw] = CHIP_SHORT_LABELS[pathChip]
        continue
      }

      const plannedChip = (Object.entries(chipResolvedPlan).find(
        ([, chipGw]) => chipGw === gw
      )?.[0] ?? null) as keyof ChipPlan | null
      if (plannedChip && CHIP_SHORT_LABELS[plannedChip]) {
        timeline[gw] = CHIP_SHORT_LABELS[plannedChip]
      }
    }

    return timeline
  }, [predictionsRange?.gameweeks, selectedPath, chipResolvedPlan])

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

  const rationaleByGw = useMemo((): GameweekRationaleRow[] => {
    if (!predictionsRange?.gameweeks) return []

    const rationalePath = selectedPath ?? paths[0] ?? null
    const activeObjectiveMode = rationalePath ? solveObjectiveMode : objectiveMode

    const fromPath = (gw: number, pathGw: PathGameweek): GameweekRationaleRow => {
      const expectedGain = pathGw.moves.reduce((sum, move) => sum + (move.gain ?? 0), 0)
      const transferCount = pathGw.moves.length
      const chipKey = (pathGw.chip_played ?? null) as keyof ChipPlan | null
      const chipLabel = chipKey ? CHIP_LABELS[chipKey] : null

      let riskTradeoff = 'Single move keeps variance contained.'
      if (pathGw.action === 'bank') {
        riskTradeoff = `Roll preserves flexibility with ${pathGw.ft_after} FT available next GW.`
      } else if (pathGw.hit_cost > 0) {
        riskTradeoff = 'Hit cost accepted for projected upside; downside rises if returns miss.'
      } else if (chipLabel) {
        riskTradeoff = `${chipLabel} raises range of outcomes; upside is prioritized.`
      } else if (transferCount > 1) {
        riskTradeoff = 'Multiple transfers increase variance and execution risk.'
      }

      return {
        gw,
        actionLabel:
          pathGw.action === 'bank'
            ? 'Bank / roll FT'
            : `${transferCount} transfer${transferCount === 1 ? '' : 's'}`,
        expectedGain,
        hitCost: pathGw.hit_cost,
        riskTradeoff,
        objectiveMode: activeObjectiveMode,
        objectiveContext: OBJECTIVE_CONTEXT[activeObjectiveMode],
        chipLabel,
      }
    }

    return predictionsRange.gameweeks.map((gw) => {
      const pathGw = rationalePath?.transfers_by_gw?.[gw]
      if (pathGw) {
        return fromPath(gw, pathGw)
      }

      const gwTransferCount = userTransfers.filter((transfer) => transfer.gameweek === gw).length
      const manualHitCost = gw === firstGw ? hitsCost : 0
      const actionLabel =
        gwTransferCount > 0
          ? `${gwTransferCount} manual transfer${gwTransferCount === 1 ? '' : 's'}`
          : 'Bank / roll FT'

      return {
        gw,
        actionLabel,
        expectedGain: gwTransferCount > 0 ? null : 0,
        hitCost: manualHitCost,
        riskTradeoff:
          gwTransferCount > 0
            ? 'Manual action queued; run solver to quantify gain and downside.'
            : 'No move queued; flexibility is preserved for later weeks.',
        objectiveMode: activeObjectiveMode,
        objectiveContext: OBJECTIVE_CONTEXT[activeObjectiveMode],
        chipLabel: chipTimelineByGw[gw] ? `Chip: ${chipTimelineByGw[gw]}` : null,
      }
    })
  }, [
    predictionsRange?.gameweeks,
    selectedPath,
    paths,
    solveObjectiveMode,
    objectiveMode,
    userTransfers,
    firstGw,
    hitsCost,
    chipTimelineByGw,
  ])

  const planComparison = useMemo(() => {
    if (
      comparePlanAIndex === null ||
      comparePlanBIndex === null ||
      !paths[comparePlanAIndex] ||
      !paths[comparePlanBIndex]
    ) {
      return null
    }

    const planA = paths[comparePlanAIndex]
    const planB = paths[comparePlanBIndex]
    const horizon = optimizeData?.planning_horizon ?? []

    const describeTransfers = (gwData: PathGameweek | undefined): string => {
      if (!gwData || gwData.moves.length === 0) return 'Roll'
      return gwData.moves.map((move) => `${move.out_name} -> ${move.in_name}`).join(', ')
    }

    const describeChip = (gwData: PathGameweek | undefined): string => {
      const chip = gwData?.chip_played as keyof ChipPlan | null | undefined
      if (!chip) return '-'
      return CHIP_SHORT_LABELS[chip] ?? chip
    }

    let cumulativeDelta = 0
    const rows = horizon.map((gw) => {
      const gwA = planA.transfers_by_gw[gw]
      const gwB = planB.transfers_by_gw[gw]
      const pointsA = gwA?.gw_score ?? 0
      const pointsB = gwB?.gw_score ?? 0
      const gwDelta = pointsA - pointsB
      cumulativeDelta += gwDelta

      return {
        gw,
        pointsA,
        pointsB,
        gwDelta,
        cumulativeDelta,
        transfersA: describeTransfers(gwA),
        transfersB: describeTransfers(gwB),
        chipA: describeChip(gwA),
        chipB: describeChip(gwB),
      }
    })

    return {
      planA,
      planB,
      rows,
      cumulativeDelta,
    }
  }, [comparePlanAIndex, comparePlanBIndex, paths, optimizeData?.planning_horizon])

  // Get the selected player's data
  const selectedPlayerData = useMemo(() => {
    if (!selectedPlayer || !playersData?.players) return null
    return playersData.players.find((p) => p.id === selectedPlayer) ?? null
  }, [selectedPlayer, playersData?.players])

  // Lock body scroll when drawer is open
  useEffect(() => {
    if (selectedPlayerData) {
      document.body.style.overflow = 'hidden'
      return () => {
        document.body.style.overflow = ''
      }
    }
  }, [selectedPlayerData])

  // Available replacements for the selected player
  const availableReplacements = useMemo(() => {
    if (!selectedPlayerData || !predictionsRange?.players || !playersData?.players) return []

    const position = selectedPlayerData.element_type
    const maxBudget = selectedPlayerData.now_cost / 10 + effectiveBank

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
          p.team === selectedPlayerData.team ? currentTeamCount - 1 : currentTeamCount
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
    selectedPlayerData,
    predictionsRange?.players,
    playersData?.players,
    effectiveSquad,
    effectiveBank,
    replacementSearch,
  ])

  // Persist manager ID + constraints to URL and localStorage
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (managerId) {
      localStorage.setItem('fpl_manager_id', String(managerId))
      params.set('manager', String(managerId))
    } else {
      params.delete('manager')
    }

    if (!parsedConstraints.hasErrors && Object.keys(parsedConstraints.constraints).length > 0) {
      const serialized = JSON.stringify(parsedConstraints.constraints)
      localStorage.setItem('fpl_planner_constraints', serialized)
      params.set('constraints', serialized)
    } else {
      localStorage.removeItem('fpl_planner_constraints')
      params.delete('constraints')
    }

    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`)
  }, [managerId, parsedConstraints])

  const handleLoadManager = () => {
    const id = parseInt(managerInput, 10)
    if (!isNaN(id) && id > 0) {
      setManagerId(id)
      setSelectedPlayer(null)
      setSelectedPathIndex(null)
      setUserTransfers([])
      setSolveRequested(false)
      setSolveTransfers([])
      setSolveChipPlan({})
      setSolveChipMode('locked')
      setSolveChipCompare(true)
      setSolveFtValue(1.5)
      setSolveDepth('standard')
      setSolveObjectiveMode('expected')
      setSolveConstraints({})
      setFreeTransfers(null)
    }
  }

  const setChipWeek = (chip: keyof ChipPlan, gameweekValue: string) => {
    setChipPlan((prev) => {
      const next: ChipPlan = { ...prev }
      if (!gameweekValue) {
        delete next[chip]
      } else {
        next[chip] = parseInt(gameweekValue, 10)
      }
      return next
    })
    setSelectedPathIndex(null)
  }

  const handlePlayerSelect = (playerId: number) => {
    if (selectedPlayer === playerId) {
      setSelectedPlayer(null)
      setReplacementSearch('')
    } else {
      setSelectedPlayer(playerId)
      setSidebarTab('projections')
      setReplacementSearch('')
    }
  }

  const handleSelectReplacement = (replacement: PlayerMultiWeekPrediction) => {
    if (!selectedPlayerData || selectedGameweek === null) return

    let newTransfers = [...userTransfers]

    // If the outgoing player was brought in by an existing transfer for this GW,
    // update that transfer instead of adding a new one
    const existingIdx = newTransfers.findIndex(
      (ft) => ft.in === selectedPlayerData.id && ft.gameweek === selectedGameweek
    )
    if (existingIdx !== -1) {
      // If replacing back to the original player, the transfer is a no-op — remove it
      if (newTransfers[existingIdx].out === replacement.player_id) {
        newTransfers = newTransfers.filter((_, i) => i !== existingIdx)
      } else {
        newTransfers = newTransfers.map((ft, i) =>
          i === existingIdx ? { ...ft, in: replacement.player_id } : ft
        )
      }
    } else {
      newTransfers.push({
        gameweek: selectedGameweek,
        out: selectedPlayerData.id,
        in: replacement.player_id,
      })
    }

    setUserTransfers(newTransfers)
    setSelectedPathIndex(null)
    setSelectedPlayer(null)
    setReplacementSearch('')
  }

  const handleReset = () => {
    setSelectedPlayer(null)
    setReplacementSearch('')
    setSelectedPathIndex(null)
    setUserTransfers([])
    setSolveRequested(false)
    setSolveTransfers([])
    setSolveChipPlan({})
    setSolveChipMode('locked')
    setSolveChipCompare(true)
    setSolveFtValue(1.5)
    setSolveDepth('standard')
    setSolveObjectiveMode('expected')
    setSolveConstraints({})
    setChipPlan({})
    setChipMode('locked')
    setChipCompare(true)
    setObjectiveMode('expected')
    setLockIdsInput('')
    setAvoidIdsInput('')
    setMaxHitsInput('')
    setChipWindowInputs({
      wildcard: '',
      free_hit: '',
      bench_boost: '',
      triple_captain: '',
    })
  }

  const handleFindPlans = () => {
    if (parsedConstraints.hasErrors) {
      return
    }
    setShowSolveLoader(true)
    setSolveTransfers([...userTransfers])
    setSolveChipPlan({ ...chipPlan })
    setSolveChipMode(chipMode)
    setSolveChipCompare(chipCompare)
    setSolveFtValue(ftValue)
    setSolveDepth(solverDepth)
    setSolveObjectiveMode(objectiveMode)
    setSolveConstraints(parsedConstraints.constraints)
    setSolveRequested(true)
    // Minimum display time so the skeleton always appears
    setTimeout(() => setShowSolveLoader(false), 800)
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
    setSelectedPlayer(null)
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

  const applyDecay = (baseMins: number, gws: number[]): Record<number, number> => {
    const result: Record<number, number> = {}
    for (let i = 0; i < gws.length; i++) {
      result[gws[i]] = Math.round(baseMins * Math.pow(0.97, i))
    }
    return result
  }

  const handleXMinsChange = (playerId: number, xMins: number, gameweek?: number) => {
    setXMinsOverrides((prev) => {
      const existing =
        typeof prev[playerId] === 'object' && prev[playerId] !== null
          ? { ...(prev[playerId] as Record<number, number>) }
          : {}
      if (gameweek !== undefined) {
        // Per-GW override from drawer — replace just this GW
        existing[gameweek] = xMins
      } else {
        // Explorer override — apply with 0.97 decay to all GWs
        const gws = predictionsRange?.gameweeks ?? []
        const decayed = applyDecay(xMins, gws)
        Object.assign(existing, decayed)
      }
      return { ...prev, [playerId]: existing }
    })
  }

  // Get effective points: raw predictions by default, scaled only with user xMins override
  const getEffectivePoints = (playerId: number, gw: number): number => {
    const pred = playerPredictionsMap.get(playerId)
    // No fixture in this GW = blank gameweek, no points
    if (pred && !predictionsRange?.fixtures?.[pred.team]?.[gw]?.length) return 0

    const override = debouncedXMins[playerId]
    if (typeof override === 'object' && override !== null && override[gw] != null) {
      const ifFitPts = pred?.if_fit_predictions?.[gw] ?? pred?.predictions[gw] ?? 0
      const ifFitMins = pred?.expected_mins_if_fit ?? 90
      return scalePoints(ifFitPts, ifFitMins, override[gw])
    }

    return pred?.predictions[gw] ?? 0
  }

  // Build formation players for the selected gameweek
  const formationPlayers = useMemo(() => {
    if (!effectiveSquad.length || !playersData?.players || selectedGameweek === null) return []

    const fixturesData = predictionsRange?.fixtures

    const squadWithData = effectiveSquad
      .map((playerId) => {
        const player = playersData.players.find((p) => p.id === playerId)
        if (!player) return null

        const teamFixtures = fixturesData?.[player.team]?.[selectedGameweek]
        const fixture = teamFixtures ? formatFixtures(teamFixtures) : undefined

        return {
          player_id: playerId,
          web_name: player.web_name,
          element_type: player.element_type,
          team: player.team,
          predicted_points: getEffectivePoints(playerId, selectedGameweek),
          fixture,
        }
      })
      .filter((p): p is NonNullable<typeof p> => p !== null)

    return buildFormation(squadWithData)
  }, [
    effectiveSquad,
    playersData?.players,
    playerPredictionsMap,
    selectedGameweek,
    debouncedXMins,
    predictionsRange?.fixtures,
  ])

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
          if (!player) return null
          const pts = getEffectivePoints(playerId, gw)

          return {
            player_id: playerId,
            web_name: player.web_name,
            element_type: player.element_type,
            team: player.team,
            predicted_points: pts,
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
    debouncedXMins,
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
    <>
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
        <div className="grid md:grid-cols-2 gap-4 animate-fade-in-up animation-delay-100">
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
        </div>

        {(squadError || solveError) && (
          <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
            {solveError?.message || squadError?.message || 'Failed to load optimization data'}
          </div>
        )}

        {isLoadingSquad && managerId && (
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

            {/* Solver & Recommended Plans */}
            <BroadcastCard title="Recommended Plans" accentColor="purple" animationDelay={175}>
              {/* Solver controls */}
              <div className="flex flex-col sm:flex-row items-start sm:items-end gap-3 mb-4">
                <div className="flex items-end gap-2 flex-1 min-w-0">
                  <button
                    onClick={handleFindPlans}
                    disabled={isSolveActive || !managerId || parsedConstraints.hasErrors}
                    className={`btn-primary relative ${isStale ? 'ring-2 ring-yellow-400/50' : ''}`}
                  >
                    {isSolveActive ? (
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
                <div className="flex items-center gap-3">
                  <div className="flex items-center gap-1">
                    {(Object.keys(OBJECTIVE_LABELS) as PlannerObjectiveMode[]).map((mode) => (
                      <button
                        key={mode}
                        onClick={() => setObjectiveMode(mode)}
                        className={`px-2 py-0.5 rounded text-[10px] font-display uppercase tracking-wider transition-colors ${
                          objectiveMode === mode
                            ? 'bg-highlight/20 text-highlight border border-highlight/30'
                            : 'text-foreground-muted hover:text-foreground hover:bg-surface-hover'
                        }`}
                      >
                        {OBJECTIVE_LABELS[mode]}
                      </button>
                    ))}
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-foreground-muted whitespace-nowrap">FT</span>
                    <input
                      type="range"
                      min="0"
                      max="5"
                      step="0.5"
                      value={ftValue}
                      onChange={(e) => setFtValue(parseFloat(e.target.value))}
                      className="w-24 accent-fpl-purple"
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

              <div className="grid md:grid-cols-2 gap-3 mb-4">
                <div className="p-3 rounded-lg bg-surface-elevated border border-border/60">
                  <div className="flex items-center justify-between mb-2">
                    <span className="font-display text-[10px] uppercase tracking-wider text-foreground-muted">
                      Chip Strategy
                    </span>
                    <select
                      value={chipMode}
                      onChange={(e) => {
                        setChipMode(e.target.value as ChipMode)
                        setSelectedPathIndex(null)
                      }}
                      className="input-broadcast py-1 text-xs min-w-[110px]"
                    >
                      <option value="locked">Locked</option>
                      <option value="auto">Auto</option>
                      <option value="none">None</option>
                    </select>
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {(Object.keys(CHIP_LABELS) as (keyof ChipPlan)[]).map((chip) => (
                      <label key={chip} className="flex items-center justify-between gap-2">
                        <span className="text-xs text-foreground-muted">
                          {CHIP_SHORT_LABELS[chip]}
                        </span>
                        <select
                          value={chipPlan[chip] ?? ''}
                          onChange={(e) => setChipWeek(chip, e.target.value)}
                          disabled={chipMode === 'none'}
                          className="input-broadcast py-1 text-xs min-w-[88px]"
                        >
                          <option value="">Auto</option>
                          {optimizeData.planning_horizon.map((gw) => (
                            <option key={`${chip}-${gw}`} value={gw}>
                              GW{gw}
                            </option>
                          ))}
                        </select>
                      </label>
                    ))}
                  </div>
                  <label className="mt-2 inline-flex items-center gap-2 text-xs text-foreground-muted">
                    <input
                      type="checkbox"
                      checked={chipCompare}
                      onChange={(e) => setChipCompare(e.target.checked)}
                      disabled={chipMode === 'none'}
                    />
                    Compare chip plan vs no chips
                  </label>
                </div>

                <div className="p-3 rounded-lg bg-surface-elevated border border-border/60">
                  <div className="font-display text-[10px] uppercase tracking-wider text-foreground-muted mb-2">
                    Chip Signals
                  </div>
                  <div className="space-y-1 text-xs">
                    {(Object.keys(CHIP_LABELS) as (keyof ChipPlan)[]).map((chip) => {
                      const gw = chipResolvedPlan[chip]
                      const top = chipSuggestions[chip]?.[0]
                      return (
                        <div
                          key={`signal-${chip}`}
                          className="flex items-center justify-between gap-2"
                        >
                          <span className="text-foreground-muted">{CHIP_LABELS[chip]}</span>
                          <span className="font-mono text-foreground">
                            {gw ? `GW${gw}` : top ? `Best GW${top.gameweek}` : 'n/a'}
                          </span>
                        </div>
                      )
                    })}
                  </div>
                  {comparison && (
                    <div className="mt-3 pt-2 border-t border-border/50 text-xs">
                      <div className="flex items-center justify-between text-foreground-muted">
                        <span>Chip delta</span>
                        <span
                          className={`font-mono ${
                            (comparison.chip_delta ?? 0) >= 0
                              ? 'text-fpl-green'
                              : 'text-destructive'
                          }`}
                        >
                          {(comparison.chip_delta ?? 0) >= 0 ? '+' : ''}
                          {(comparison.chip_delta ?? 0).toFixed(1)}
                        </span>
                      </div>
                    </div>
                  )}
                </div>
              </div>

              <div className="mb-4 p-3 rounded-lg bg-surface-elevated border border-border/60">
                <div className="font-display text-[10px] uppercase tracking-wider text-foreground-muted mb-2">
                  Constraints
                </div>
                <div className="grid md:grid-cols-2 gap-3">
                  <label className="block text-xs text-foreground-muted">
                    Lock Player IDs
                    <input
                      data-testid="constraints-lock-ids"
                      value={lockIdsInput}
                      onChange={(e) => setLockIdsInput(e.target.value)}
                      placeholder="e.g. 13, 8"
                      className="input-broadcast mt-1"
                    />
                  </label>
                  <label className="block text-xs text-foreground-muted">
                    Avoid Player IDs
                    <input
                      data-testid="constraints-avoid-ids"
                      value={avoidIdsInput}
                      onChange={(e) => setAvoidIdsInput(e.target.value)}
                      placeholder="e.g. 6, 22"
                      className="input-broadcast mt-1"
                    />
                  </label>
                  <label className="block text-xs text-foreground-muted">
                    Max Hits
                    <input
                      data-testid="constraints-max-hits"
                      type="number"
                      min={0}
                      value={maxHitsInput}
                      onChange={(e) => setMaxHitsInput(e.target.value)}
                      placeholder="No cap"
                      className="input-broadcast mt-1"
                    />
                  </label>
                  <div className="space-y-1">
                    <div className="text-xs text-foreground-muted">Chip Windows (comma-separated GWs)</div>
                    {(Object.keys(CHIP_LABELS) as (keyof ChipPlan)[]).map((chip) => (
                      <label key={`window-${chip}`} className="flex items-center gap-2 text-xs text-foreground-muted">
                        <span className="w-10">{CHIP_SHORT_LABELS[chip]}</span>
                        <input
                          data-testid={`constraints-chip-window-${chip}`}
                          value={chipWindowInputs[chip]}
                          onChange={(e) =>
                            setChipWindowInputs((prev) => ({ ...prev, [chip]: e.target.value }))
                          }
                          placeholder="27, 30"
                          className="input-broadcast flex-1 py-1"
                        />
                      </label>
                    ))}
                  </div>
                </div>
                {parsedConstraints.hasErrors && (
                  <div className="mt-2 text-xs text-destructive">
                    {parsedConstraints.errors.join(' | ')}
                  </div>
                )}
              </div>

              {/* Loading skeleton */}
              {isSolveActive && (
                <div className="pt-3 border-t border-border/50">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    {[0, 1, 2].map((i) => (
                      <div key={i} className="p-3 rounded-lg bg-surface-elevated animate-pulse">
                        <div className="h-3 w-16 bg-foreground/10 rounded mb-3" />
                        <div className="h-6 w-24 bg-fpl-purple/10 rounded mb-3" />
                        <div className="space-y-1.5">
                          <div className="h-2.5 w-full bg-foreground/5 rounded" />
                          <div className="h-2.5 w-3/4 bg-foreground/5 rounded" />
                          <div className="h-2.5 w-5/6 bg-foreground/5 rounded" />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Plan cards — only after solving completes */}
              {!isSolveActive && paths.length > 0 && (
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-3 border-t border-border/50">
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
                                {gwData.chip_played && (
                                  <span className="text-fpl-green/80">
                                    [{CHIP_SHORT_LABELS[gwData.chip_played as keyof ChipPlan]}]{' '}
                                  </span>
                                )}
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
              )}
              {!isSolveActive && paths.length > 0 && (
                <div className="pt-2 text-xs text-foreground-muted">
                  Objective: {OBJECTIVE_LABELS[solveObjectiveMode]}
                </div>
              )}
              {!isSolveActive && paths.length > 1 && (
                <div
                  className="mt-3 pt-3 border-t border-border/50 space-y-3"
                  data-testid="plan-comparison-panel"
                >
                  <div className="font-display text-[10px] uppercase tracking-wider text-foreground-muted">
                    A/B Plan Comparison
                  </div>
                  <div className="grid md:grid-cols-2 gap-3">
                    <label className="text-xs text-foreground-muted">
                      Plan A
                      <select
                        data-testid="compare-plan-a"
                        value={comparePlanAIndex ?? ''}
                        onChange={(e) =>
                          setComparePlanAIndex(
                            e.target.value === '' ? null : Number.parseInt(e.target.value, 10)
                          )
                        }
                        className="input-broadcast mt-1"
                      >
                        {paths.map((path, idx) => (
                          <option key={`compare-a-${path.id}-${idx}`} value={idx}>
                            Plan {path.id} ({path.total_score.toFixed(1)})
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="text-xs text-foreground-muted">
                      Plan B
                      <select
                        data-testid="compare-plan-b"
                        value={comparePlanBIndex ?? ''}
                        onChange={(e) =>
                          setComparePlanBIndex(
                            e.target.value === '' ? null : Number.parseInt(e.target.value, 10)
                          )
                        }
                        className="input-broadcast mt-1"
                      >
                        {paths.map((path, idx) => (
                          <option key={`compare-b-${path.id}-${idx}`} value={idx}>
                            Plan {path.id} ({path.total_score.toFixed(1)})
                          </option>
                        ))}
                      </select>
                    </label>
                  </div>
                  {planComparison && (
                    <div className="space-y-2">
                      {planComparison.rows.map((row) => (
                        <div
                          key={`comparison-gw-${row.gw}`}
                          data-testid={`comparison-row-gw-${row.gw}`}
                          className="rounded-lg border border-border/50 bg-surface-elevated/60 p-2.5"
                        >
                          <div className="flex items-center justify-between text-xs">
                            <span className="font-display uppercase tracking-wider text-foreground-muted">
                              GW{row.gw}
                            </span>
                            <span className={row.gwDelta >= 0 ? 'text-fpl-green' : 'text-destructive'}>
                              Delta {row.gwDelta >= 0 ? '+' : ''}
                              {row.gwDelta.toFixed(1)}
                            </span>
                          </div>
                          <div className="mt-1 text-xs text-foreground">
                            Points: A {row.pointsA.toFixed(1)} vs B {row.pointsB.toFixed(1)} | Cumulative:{' '}
                            {row.cumulativeDelta >= 0 ? '+' : ''}
                            {row.cumulativeDelta.toFixed(1)}
                          </div>
                          <div className="mt-1 text-xs text-foreground-muted">
                            Transfers: A {row.transfersA} | B {row.transfersB}
                          </div>
                          <div className="text-xs text-foreground-muted">
                            Chips: A {row.chipA} | B {row.chipB}
                          </div>
                        </div>
                      ))}
                      <div className="text-xs text-foreground" data-testid="comparison-cumulative-delta">
                        Final cumulative delta:{' '}
                        <span className={planComparison.cumulativeDelta >= 0 ? 'text-fpl-green' : 'text-destructive'}>
                          {planComparison.cumulativeDelta >= 0 ? '+' : ''}
                          {planComparison.cumulativeDelta.toFixed(1)}
                        </span>{' '}
                        (Plan {planComparison.planA.id} vs Plan {planComparison.planB.id})
                      </div>
                    </div>
                  )}
                </div>
              )}
            </BroadcastCard>

            {/* Gameweek Selector */}
            <BroadcastCard title="Select Gameweek" animationDelay={200}>
              <div className="mb-3 p-2.5 rounded-lg bg-surface-elevated/60 border border-border/50">
                <div className="text-[10px] font-display uppercase tracking-wider text-foreground-muted mb-1.5">
                  Chip Timeline
                </div>
                <div className="flex gap-2 flex-wrap">
                  {predictionsRange?.gameweeks.map((gw) => (
                    <div
                      key={`chip-timeline-${gw}`}
                      className={`px-2 py-1 rounded border text-center min-w-[62px] ${
                        chipTimelineByGw[gw]
                          ? 'bg-fpl-green/10 border-fpl-green/30'
                          : 'bg-surface border-border/40'
                      }`}
                    >
                      <div className="text-[10px] text-foreground-muted">GW{gw}</div>
                      <div className="text-[11px] font-display tracking-wider text-foreground">
                        {chipTimelineByGw[gw] ?? '-'}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

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
                          {pathGw?.chip_played
                            ? CHIP_LABELS[pathGw.chip_played as keyof ChipPlan]
                            : pathAction === 'bank'
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

              <div className="mt-3 pt-3 border-t border-border/50">
                <div className="text-[10px] font-display uppercase tracking-wider text-foreground-muted mb-2">
                  Per-GW Rationale
                </div>
                <div className="space-y-2">
                  {rationaleByGw.map((row) => (
                    <div
                      key={`rationale-${row.gw}`}
                      data-testid={`rationale-gw-${row.gw}`}
                      className="rounded-lg border border-border/50 bg-surface-elevated/60 p-2.5"
                    >
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-display text-foreground-muted uppercase tracking-wider">
                          GW{row.gw} - {row.actionLabel}
                        </span>
                        {row.chipLabel && <span className="text-fpl-green">{row.chipLabel}</span>}
                      </div>
                      <div className="mt-1 text-xs text-foreground">
                        Expected gain:{' '}
                        {row.expectedGain === null
                          ? 'pending'
                          : `${row.expectedGain >= 0 ? '+' : ''}${row.expectedGain.toFixed(1)}`}{' '}
                        | Hit cost: -{row.hitCost.toFixed(1)} | Objective:{' '}
                        {OBJECTIVE_LABELS[row.objectiveMode]}
                      </div>
                      <div className="mt-1 text-xs text-foreground-muted">{row.riskTradeoff}</div>
                      <div className="text-[11px] text-foreground-dim">{row.objectiveContext}</div>
                    </div>
                  ))}
                </div>
              </div>
            </BroadcastCard>

            {/* Squad Formation */}
            <BroadcastCard
              title={`Your Squad \u2014 GW${selectedGameweek ?? '?'} Predictions`}
              accentColor="green"
              animationDelay={300}
            >
              <p className="text-xs text-foreground-muted mb-4">
                Click a player to adjust minutes or make a transfer.
                {selectedGameweek !== null && ` Showing squad as of GW${selectedGameweek}.`}
              </p>
              {formationPlayers.length > 0 ? (
                <FormationPitch
                  players={formationPlayers}
                  teams={teamsRecord}
                  xMinsOverrides={xMinsOverrides}
                  selectedGw={selectedGameweek ?? undefined}
                  selectedPlayer={selectedPlayer}
                  newTransferIds={newTransferIds}
                  onPlayerClick={handlePlayerSelect}
                />
              ) : (
                <div className="text-center text-foreground-muted py-8">Loading squad...</div>
              )}
            </BroadcastCard>

            {/* Your Transfers — inline bar */}
            {userTransfers.length > 0 && (
              <BroadcastCard title="Your Transfers" accentColor="green" animationDelay={200}>
                <div className="flex flex-wrap gap-2 items-center">
                  {Object.entries(transfersByGw)
                    .sort(([a], [b]) => Number(a) - Number(b))
                    .map(([gwStr, transfers]) => (
                      <div key={gwStr} className="flex items-center gap-2">
                        <span className="font-display text-[10px] uppercase tracking-wider text-foreground-muted">
                          GW{gwStr}:
                        </span>
                        {transfers.map((ft, idx) => {
                          const outPlayer = playersData?.players.find((p) => p.id === ft.out)
                          const inPlayer = playersData?.players.find((p) => p.id === ft.in)
                          return (
                            <div
                              key={idx}
                              className="flex items-center gap-1.5 px-2 py-1 bg-surface-elevated rounded text-sm"
                            >
                              <span className="text-destructive truncate max-w-[80px]">
                                {outPlayer?.web_name ?? `#${ft.out}`}
                              </span>
                              <span className="text-foreground-dim">{'\u2192'}</span>
                              <span className="text-fpl-green truncate max-w-[80px]">
                                {inPlayer?.web_name ?? `#${ft.in}`}
                              </span>
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
                                className="text-xs text-foreground-muted hover:text-destructive ml-1"
                              >
                                {'\u2715'}
                              </button>
                            </div>
                          )
                        })}
                      </div>
                    ))}
                  {selectedPathIndex !== null && (
                    <button onClick={handleSavePlan} className="btn-secondary text-xs">
                      Save Plan
                    </button>
                  )}
                </div>
              </BroadcastCard>
            )}

            {/* Saved Plans — inline */}
            {savedPlans.length > 0 && (
              <BroadcastCard title="Saved Plans" accentColor="purple" animationDelay={250}>
                <div className="flex flex-wrap gap-2">
                  {savedPlans.map((plan) => (
                    <div
                      key={plan.id}
                      className="flex items-center gap-3 px-3 py-2 bg-surface-elevated rounded-lg"
                    >
                      <span className="font-display text-xs uppercase tracking-wider text-foreground">
                        {plan.name}
                      </span>
                      <span
                        className={`font-mono text-sm font-bold ${plan.scoreVsHold > 0 ? 'text-fpl-green' : 'text-foreground'}`}
                      >
                        {plan.scoreVsHold > 0 ? '+' : ''}
                        {plan.scoreVsHold.toFixed(1)}
                      </span>
                      <span className="text-xs text-foreground-muted">
                        {plan.transfers.length} transfer{plan.transfers.length !== 1 ? 's' : ''}
                      </span>
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
                  ))}
                </div>
              </BroadcastCard>
            )}
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
                  fixtures={predictionsRange.fixtures}
                  onXMinsChange={handleXMinsChange}
                  onResetXMins={() => setXMinsOverrides({})}
                  onPlayerClick={handlePlayerSelect}
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

      {/* Player Detail Drawer — rendered outside space-y-6 to avoid margin artifacts */}
      {selectedPlayerData && (
        <div className="fixed inset-0 z-50">
          {/* Backdrop */}
          <div
            className="absolute inset-0 bg-black/40 animate-fade-in"
            onClick={() => {
              setSelectedPlayer(null)
              setReplacementSearch('')
            }}
          />

          {/* Drawer panel */}
          <div className="absolute top-0 right-0 h-full w-full max-w-sm bg-surface border-l border-fpl-green/20 shadow-2xl shadow-black/50 flex flex-col animate-drawer-slide-in">
            <div className="flex-1 overflow-y-auto">
              <PlayerDetailPanel
                player={selectedPlayerData}
                teamName={teamsMap.get(selectedPlayerData.team) ?? '???'}
                activeTab={sidebarTab}
                onTabChange={setSidebarTab}
                onClose={() => {
                  setSelectedPlayer(null)
                  setReplacementSearch('')
                }}
                playerPrediction={playerPredictionsMap.get(selectedPlayerData.id)}
                gameweeks={predictionsRange?.gameweeks ?? []}
                selectedGw={selectedGameweek}
                xMinsOverrides={xMinsOverrides}
                onXMinsChange={handleXMinsChange}
                expectedMinsPerGw={
                  playerPredictionsMap.get(selectedPlayerData.id)?.expected_mins ?? {}
                }
                expectedMinsIfFit={
                  playerPredictionsMap.get(selectedPlayerData.id)?.expected_mins_if_fit ?? 90
                }
                fixtures={predictionsRange?.fixtures?.[selectedPlayerData.team]}
                budget={selectedPlayerData.now_cost / 10 + effectiveBank}
                replacementSearch={replacementSearch}
                onReplacementSearchChange={setReplacementSearch}
                availableReplacements={availableReplacements}
                currentPlayerPredicted={
                  playerPredictionsMap.get(selectedPlayerData.id)?.total_predicted ?? 0
                }
                teamsMap={teamsMap}
                onSelectReplacement={handleSelectReplacement}
              />
            </div>
          </div>
        </div>
      )}
    </>
  )
}
