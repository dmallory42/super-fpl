import { useState, useMemo, useEffect, useRef, useCallback } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { usePlannerOptimize } from '../hooks/usePlannerOptimize'
import { usePredictionsRange } from '../hooks/usePredictionsRange'
import type {
  ChipPlan,
  PlayerMultiWeekPrediction,
  FixedTransfer,
  PlannerConstraints,
  PlannerObjectiveMode,
  XMinsOverrides,
  FixtureOpponent,
} from '../api/client'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { EmptyState, ChartIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonCard } from '../components/ui/SkeletonLoader'
import {
  FormSectionCard,
  FormField,
  FormInput,
  FormSelect,
  SearchResultsList,
  SearchResultButton,
  SelectionPill,
  SelectionPillList,
} from '../components/ui/form'
import { FormationPitch } from '../components/live/FormationPitch'
import { useLiveSamples } from '../hooks/useLiveSamples'
import { PlayerExplorer } from '../components/planner/PlayerExplorer'
import { PlayerDetailPanel } from '../components/planner/PlayerDetailPanel'
import { buildFormation } from '../lib/formation'

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
const DEFAULT_SOLVER_DEPTH = 'deep'
const DEFAULT_PLANNING_HORIZON = 6
const MAX_PLANNING_HORIZON = 12

function formatFixtures(fixtures: FixtureOpponent[]): string {
  return fixtures.map((f) => (f.is_home ? f.opponent : f.opponent.toLowerCase())).join(', ')
}

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

interface SavedPlan {
  id: string
  name: string
  transfers: FixedTransfer[]
  score: number
  scoreVsHold: number
}

interface ConstraintInputState {
  lockIds: number[]
  avoidIds: number[]
  avoidTeams: number[]
}

type ChipType = keyof ChipPlan

function isUnlimitedTransferChip(chip: ChipType | undefined): boolean {
  return chip === 'wildcard' || chip === 'free_hit'
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

function getInitialPlanningHorizon(): number {
  const params = new URLSearchParams(window.location.search)
  const fromUrl = params.get('horizon')
  const fromStorage = localStorage.getItem('fpl_planner_horizon')
  const raw = fromUrl ?? fromStorage
  const parsed = raw ? parseInt(raw, 10) : NaN
  if (!isNaN(parsed)) {
    return Math.max(1, Math.min(MAX_PLANNING_HORIZON, parsed))
  }
  return DEFAULT_PLANNING_HORIZON
}

function getInitialConstraintInputs(): ConstraintInputState {
  const empty: ConstraintInputState = {
    lockIds: [],
    avoidIds: [],
    avoidTeams: [],
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

  return {
    lockIds: Array.isArray(source.lock_ids)
      ? source.lock_ids.filter((id) => Number.isInteger(id) && id > 0)
      : [],
    avoidIds: Array.isArray(source.avoid_ids)
      ? source.avoid_ids.filter((id) => Number.isInteger(id) && id > 0)
      : [],
    avoidTeams: Array.isArray((source as { avoid_teams?: unknown }).avoid_teams)
      ? ((source as { avoid_teams: unknown[] }).avoid_teams as number[]).filter(
          (id) => Number.isInteger(id) && id > 0
        )
      : [],
  }
}

export function Planner() {
  const initial = getInitialManagerId()
  const [managerId, setManagerId] = useState<number | null>(initial.id)
  const [managerInput, setManagerInput] = useState(initial.input)
  const [freeTransfers, setFreeTransfers] = useState<number | null>(null)
  const [chipPlan, setChipPlan] = useState<ChipPlan>({})
  const [xMinsOverrides, setXMinsOverrides] = useState<XMinsOverrides>({})
  const [selectedGameweek, setSelectedGameweek] = useState<number | null>(null)

  // Path solver state
  const [selectedPathIndex, setSelectedPathIndex] = useState<number | null>(null)
  const [ftValue, setFtValue] = useState(1.5)
  const [objectiveMode, setObjectiveMode] = useState<PlannerObjectiveMode>('expected')
  const [planningHorizon, setPlanningHorizon] = useState<number>(getInitialPlanningHorizon)
  const initialConstraintInputs = getInitialConstraintInputs()
  const [lockIds, setLockIds] = useState<number[]>(initialConstraintInputs.lockIds)
  const [avoidIds, setAvoidIds] = useState<number[]>(initialConstraintInputs.avoidIds)
  const [avoidTeamIds, setAvoidTeamIds] = useState<number[]>(initialConstraintInputs.avoidTeams)
  const [lockSearch, setLockSearch] = useState('')
  const [avoidSearch, setAvoidSearch] = useState('')
  const [avoidTeamSearch, setAvoidTeamSearch] = useState('')

  // User transfers (replaces old fixedTransfers concept)
  const [userTransfers, setUserTransfers] = useState<FixedTransfer[]>([])

  // Solve state — controls whether the solver runs
  const [solveRequested, setSolveRequested] = useState(false)
  const [solveTransfers, setSolveTransfers] = useState<FixedTransfer[]>([])
  const [solveChipPlan, setSolveChipPlan] = useState<ChipPlan>({})
  const [solveFtValue, setSolveFtValue] = useState(1.5)
  const [solveObjectiveMode, setSolveObjectiveMode] = useState<PlannerObjectiveMode>('expected')
  const [solveConstraints, setSolveConstraints] = useState<PlannerConstraints>({})
  const [solvePlanningHorizon, setSolvePlanningHorizon] = useState(getInitialPlanningHorizon)
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
  const [showAdvancedSettings, setShowAdvancedSettings] = useState(false)

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

  const { data: playersData } = usePlayers()

  const parsedConstraints = useMemo(() => {
    const normalizedLockIds = Array.from(
      new Set(lockIds.filter((id) => Number.isInteger(id) && id > 0))
    )
    const normalizedManualAvoidIds = Array.from(
      new Set(avoidIds.filter((id) => Number.isInteger(id) && id > 0))
    )
    const normalizedAvoidTeams = Array.from(
      new Set(avoidTeamIds.filter((id) => Number.isInteger(id) && id > 0))
    )
    const teamExpandedAvoidIds = (playersData?.players ?? [])
      .filter((player) => normalizedAvoidTeams.includes(player.team))
      .map((player) => player.id)
    const normalizedAvoidIds = Array.from(
      new Set([...normalizedManualAvoidIds, ...teamExpandedAvoidIds])
    )
    const overlap = normalizedLockIds.filter((id) => normalizedAvoidIds.includes(id))

    const errors: string[] = []
    if (overlap.length > 0) {
      errors.push(`Lock and avoid overlap on IDs: ${overlap.join(', ')}`)
    }

    const constraints: PlannerConstraints = {}
    if (normalizedLockIds.length > 0) constraints.lock_ids = normalizedLockIds
    if (normalizedAvoidIds.length > 0) constraints.avoid_ids = normalizedAvoidIds
    if (normalizedAvoidTeams.length > 0) constraints.avoid_teams = normalizedAvoidTeams

    return {
      constraints,
      errors,
      hasErrors: errors.length > 0,
    }
  }, [lockIds, avoidIds, avoidTeamIds, playersData?.players])

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
    DEFAULT_SOLVER_DEPTH,
    planningHorizon,
    true, // skipSolve
    'locked',
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
    DEFAULT_SOLVER_DEPTH,
    solvePlanningHorizon,
    false, // run solver
    'locked',
    solveObjectiveMode,
    solveConstraints,
    false
  )

  // Stale detection — user changed transfers after solving
  const isStale =
    solveRequested &&
    (JSON.stringify(userTransfers) !== JSON.stringify(solveTransfers) ||
      JSON.stringify(chipPlan) !== JSON.stringify(solveChipPlan) ||
      ftValue !== solveFtValue ||
      objectiveMode !== solveObjectiveMode ||
      planningHorizon !== solvePlanningHorizon ||
      JSON.stringify(parsedConstraints.constraints) !== JSON.stringify(solveConstraints))

  const isSolveActive = showSolveLoader || isFetchingSolve

  // Merge squad + solve data for display
  const optimizeData = squadData
  const paths = solveData?.paths ?? []
  const displayHorizonLength = optimizeData?.planning_horizon?.length ?? planningHorizon

  // Get predictions for multiple gameweeks
  const predictionStartGw = optimizeData?.current_gameweek
  const predictionEndGw = predictionStartGw
    ? Math.min(38, predictionStartGw + planningHorizon - 1)
    : undefined
  const { data: predictionsRange } = usePredictionsRange(
    predictionStartGw,
    predictionEndGw,
    !!managerId && !!predictionStartGw,
    debouncedXMins
  )

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
    if (
      predictionsRange &&
      selectedGameweek !== null &&
      !predictionsRange.gameweeks.includes(selectedGameweek)
    ) {
      const nextGw = predictionsRange.gameweeks[0]
      if (nextGw) {
        setSelectedGameweek(nextGw)
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

  const teamsById = useMemo(() => {
    const byId = new Map<number, { id: number; name: string; short_name: string }>()
    for (const team of playersData?.teams ?? []) {
      byId.set(team.id, team)
    }
    return byId
  }, [playersData?.teams])

  const maxSelectableHorizon = useMemo(() => {
    const startGw = optimizeData?.current_gameweek ?? predictionsRange?.current_gameweek
    if (!startGw) {
      return MAX_PLANNING_HORIZON
    }
    return Math.max(1, Math.min(MAX_PLANNING_HORIZON, 39 - startGw))
  }, [optimizeData?.current_gameweek, predictionsRange?.current_gameweek])

  const horizonOptions = useMemo(
    () => Array.from({ length: maxSelectableHorizon }, (_, idx) => idx + 1),
    [maxSelectableHorizon]
  )

  useEffect(() => {
    if (planningHorizon > maxSelectableHorizon) {
      setPlanningHorizon(maxSelectableHorizon)
    }
  }, [planningHorizon, maxSelectableHorizon])

  // Teams record for FormationPitch
  const teamsRecord = useMemo(() => {
    if (!playersData?.teams) return {}
    return Object.fromEntries(
      playersData.teams.map((t) => [t.id, { id: t.id, short_name: t.short_name }])
    )
  }, [playersData?.teams])

  const playersById = useMemo(() => {
    const byId = new Map<number, { id: number; web_name: string; team: number }>()
    for (const player of playersData?.players ?? []) {
      byId.set(player.id, player)
    }
    return byId
  }, [playersData?.players])

  const lockSearchResults = useMemo(() => {
    const q = normalizeSearchText(lockSearch)
    if (!q || !playersData?.players) return []
    return playersData.players
      .filter(
        (player) => !lockIds.includes(player.id) && normalizeSearchText(player.web_name).includes(q)
      )
      .slice(0, 8)
  }, [playersData?.players, lockSearch, lockIds])

  const avoidSearchResults = useMemo(() => {
    const q = normalizeSearchText(avoidSearch)
    if (!q || !playersData?.players) return []
    return playersData.players
      .filter(
        (player) =>
          !avoidIds.includes(player.id) && normalizeSearchText(player.web_name).includes(q)
      )
      .slice(0, 8)
  }, [playersData?.players, avoidSearch, avoidIds])

  const avoidTeamSearchResults = useMemo(() => {
    const q = normalizeSearchText(avoidTeamSearch)
    if (!q || !playersData?.teams) return []
    return playersData.teams
      .filter(
        (team) =>
          !avoidTeamIds.includes(team.id) &&
          (normalizeSearchText(team.name).includes(q) ||
            normalizeSearchText(team.short_name).includes(q))
      )
      .slice(0, 8)
  }, [playersData?.teams, avoidTeamSearch, avoidTeamIds])

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
  const pathForSquadDisplay = selectedPath ?? paths[0] ?? null
  const chipByGameweek = useMemo(() => {
    const byGw: Partial<Record<number, ChipType>> = {}
    for (const [chip, gw] of Object.entries(chipPlan) as [ChipType, number][]) {
      if (gw) {
        byGw[gw] = chip
      }
    }
    return byGw
  }, [chipPlan])
  const effectiveChipByGameweek = useMemo(() => {
    const byGw: Partial<Record<number, ChipType>> = { ...chipByGameweek }
    if (!selectedPath) {
      return byGw
    }
    for (const [gwStr, gwData] of Object.entries(selectedPath.transfers_by_gw)) {
      const chip = gwData.chip_played as ChipType | undefined
      if (chip) {
        byGw[Number(gwStr)] = chip
      }
    }
    return byGw
  }, [chipByGameweek, selectedPath])

  // GW-aware effective squad: apply transfers up to and including selectedGameweek
  const effectiveSquad = useMemo(() => {
    if (!optimizeData?.current_squad?.player_ids) return []
    if (pathForSquadDisplay && selectedGameweek !== null) {
      const pathGw = pathForSquadDisplay.transfers_by_gw[selectedGameweek]
      if (
        (pathGw?.chip_played === 'free_hit' ||
          effectiveChipByGameweek[selectedGameweek] === 'free_hit') &&
        Array.isArray(pathGw?.squad_ids)
      ) {
        return pathGw.squad_ids as number[]
      }
    }
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
    pathForSquadDisplay,
    effectiveChipByGameweek,
  ])

  // GW-aware effective bank: apply transfer costs up to and including selectedGameweek
  const effectiveBank = useMemo(() => {
    if (!optimizeData?.current_squad?.bank) return 0
    if (pathForSquadDisplay && selectedGameweek !== null) {
      const pathGw = pathForSquadDisplay.transfers_by_gw[selectedGameweek]
      if (
        (pathGw?.chip_played === 'free_hit' ||
          effectiveChipByGameweek[selectedGameweek] === 'free_hit') &&
        typeof pathGw.bank === 'number'
      ) {
        return pathGw.bank
      }
    }
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
    pathForSquadDisplay,
    effectiveChipByGameweek,
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
      if (isUnlimitedTransferChip(effectiveChipByGameweek[gw])) {
        // WC/FH weeks allow unlimited moves and do not change FT balance in this model.
        continue
      }
      const transfers = transfersByGw[gw] ?? 0

      if (transfers === 0) {
        available = Math.min(5, available + 1) // bank
      } else {
        available = Math.max(1, available - transfers + 1) // consume + 1 new FT
      }
    }
    return result
  }, [predictionsRange?.gameweeks, effectiveFt, userTransfers, effectiveChipByGameweek])

  // Hits for the first GW (used by squad predictions for hit cost deduction)
  const firstGw = predictionsRange?.gameweeks[0]
  const firstGwTransfers = firstGw ? userTransfers.filter((f) => f.gameweek === firstGw).length : 0
  const firstGwHasUnlimitedChip = firstGw
    ? isUnlimitedTransferChip(effectiveChipByGameweek[firstGw])
    : false
  const hitsCount = firstGw
    ? firstGwHasUnlimitedChip
      ? 0
      : Math.max(0, firstGwTransfers - (ftByGameweek[firstGw] ?? effectiveFt))
    : 0
  const hitsCost = hitsCount * HIT_COST

  const selectedGwTransferStatus = useMemo(() => {
    if (selectedGameweek === null) {
      return null
    }
    const available = ftByGameweek[selectedGameweek] ?? effectiveFt
    const used = userTransfers.filter((f) => f.gameweek === selectedGameweek).length
    const chip = effectiveChipByGameweek[selectedGameweek]
    const hasUnlimitedChip = isUnlimitedTransferChip(chip)
    const remaining = hasUnlimitedChip ? available : Math.max(0, available - used)
    const hits = hasUnlimitedChip ? 0 : Math.max(0, used - available)
    const hitPts = hits * HIT_COST
    const statusLabel = hasUnlimitedChip
      ? `${remaining} FT (${CHIP_SHORT_LABELS[chip as keyof ChipPlan]})`
      : `${remaining} FT${hitPts > 0 ? ` (-${hitPts})` : ''}`

    return {
      available,
      used,
      hits,
      statusLabel,
    }
  }, [selectedGameweek, ftByGameweek, effectiveFt, userTransfers, effectiveChipByGameweek])

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
          !normalizeSearchText(p.web_name).includes(normalizeSearchText(replacementSearch))
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

    localStorage.setItem('fpl_planner_horizon', String(planningHorizon))
    params.set('horizon', String(planningHorizon))

    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`)
  }, [managerId, parsedConstraints, planningHorizon])

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
      setSolveFtValue(1.5)
      setSolveObjectiveMode('expected')
      setSolvePlanningHorizon(planningHorizon)
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

  const addLockPlayer = (playerId: number) => {
    setLockIds((prev) => (prev.includes(playerId) ? prev : [...prev, playerId]))
    setAvoidIds((prev) => prev.filter((id) => id !== playerId))
    setLockSearch('')
    setSelectedPathIndex(null)
  }

  const removeLockPlayer = (playerId: number) => {
    setLockIds((prev) => prev.filter((id) => id !== playerId))
    setSelectedPathIndex(null)
  }

  const addAvoidPlayer = (playerId: number) => {
    setAvoidIds((prev) => (prev.includes(playerId) ? prev : [...prev, playerId]))
    setLockIds((prev) => prev.filter((id) => id !== playerId))
    setAvoidSearch('')
    setSelectedPathIndex(null)
  }

  const removeAvoidPlayer = (playerId: number) => {
    setAvoidIds((prev) => prev.filter((id) => id !== playerId))
    setSelectedPathIndex(null)
  }

  const addAvoidTeam = (teamId: number) => {
    setAvoidTeamIds((prev) => (prev.includes(teamId) ? prev : [...prev, teamId]))
    setAvoidTeamSearch('')
    setSelectedPathIndex(null)
  }

  const removeAvoidTeam = (teamId: number) => {
    setAvoidTeamIds((prev) => prev.filter((id) => id !== teamId))
    setSelectedPathIndex(null)
  }

  const handlePlayerSelect = useCallback(
    (playerId: number) => {
      if (selectedPlayer === playerId) {
        setSelectedPlayer(null)
        setReplacementSearch('')
      } else {
        setSelectedPlayer(playerId)
        setSidebarTab('projections')
        setReplacementSearch('')
      }
    },
    [selectedPlayer]
  )

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
    setSolveFtValue(1.5)
    setSolveObjectiveMode('expected')
    setPlanningHorizon(DEFAULT_PLANNING_HORIZON)
    setSolvePlanningHorizon(DEFAULT_PLANNING_HORIZON)
    setSolveConstraints({})
    setChipPlan({})
    setShowAdvancedSettings(false)
    setObjectiveMode('expected')
    setLockIds([])
    setAvoidIds([])
    setAvoidTeamIds([])
    setLockSearch('')
    setAvoidSearch('')
    setAvoidTeamSearch('')
  }

  const handleFindPlans = () => {
    if (parsedConstraints.hasErrors) {
      return
    }
    setShowSolveLoader(true)
    setSolveTransfers([...userTransfers])
    setSolveChipPlan({ ...chipPlan })
    setSolveFtValue(ftValue)
    setSolveObjectiveMode(objectiveMode)
    setSolvePlanningHorizon(planningHorizon)
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

  const applyUniformXMins = useCallback((xMins: number, gws: number[]): Record<number, number> => {
    const result: Record<number, number> = {}
    for (const gw of gws) {
      result[gw] = xMins
    }
    return result
  }, [])

  const getBaselineXMins = useCallback(
    (playerId: number, gw: number): number | null => {
      const pred = playerPredictionsMap.get(playerId)
      if (!pred) return null
      const base = pred.expected_mins?.[gw]
      return typeof base === 'number' ? base : null
    },
    [playerPredictionsMap]
  )

  const pruneNoopOverridesForPlayer = useCallback(
    (playerId: number, overrides: Record<number, number>): Record<number, number> => {
      const pruned: Record<number, number> = {}
      for (const [gwKey, value] of Object.entries(overrides)) {
        const gw = Number(gwKey)
        if (!Number.isFinite(gw) || !Number.isFinite(value)) continue
        const baseline = getBaselineXMins(playerId, gw)
        if (baseline !== null && value === baseline) {
          continue
        }
        pruned[gw] = value
      }
      return pruned
    },
    [getBaselineXMins]
  )

  const handleXMinsChange = useCallback(
    (playerId: number, xMins: number, gameweek?: number) => {
      setXMinsOverrides((prev) => {
        const existing =
          typeof prev[playerId] === 'object' && prev[playerId] !== null
            ? { ...(prev[playerId] as Record<number, number>) }
            : {}
        if (gameweek !== undefined) {
          // Per-GW override from drawer — replace just this GW
          existing[gameweek] = xMins
        } else {
          // Explorer override — apply uniformly to all visible GWs.
          const gws = predictionsRange?.gameweeks ?? []
          const uniform = applyUniformXMins(xMins, gws)
          Object.assign(existing, uniform)
        }

        const pruned = pruneNoopOverridesForPlayer(playerId, existing)
        if (Object.keys(pruned).length === 0) {
          const next = { ...prev }
          delete next[playerId]
          return next
        }

        return { ...prev, [playerId]: pruned }
      })
    },
    [applyUniformXMins, predictionsRange?.gameweeks, pruneNoopOverridesForPlayer]
  )

  useEffect(() => {
    if (!playerPredictionsMap.size) return

    setXMinsOverrides((prev) => {
      let changed = false
      const next: XMinsOverrides = {}

      for (const [playerIdKey, rawOverride] of Object.entries(prev)) {
        const playerId = Number(playerIdKey)
        if (!Number.isFinite(playerId)) continue

        if (typeof rawOverride !== 'object' || rawOverride === null) {
          next[playerId] = rawOverride
          continue
        }

        const pruned = pruneNoopOverridesForPlayer(playerId, rawOverride as Record<number, number>)
        if (Object.keys(pruned).length > 0) {
          next[playerId] = pruned
          if (
            Object.keys(pruned).length !== Object.keys(rawOverride as Record<number, number>).length
          ) {
            changed = true
          }
        } else {
          changed = true
        }
      }

      return changed ? next : prev
    })
  }, [playerPredictionsMap])

  // Get effective points from API-recalculated predictions.
  const getEffectivePoints = (playerId: number, gw: number): number => {
    const pred = playerPredictionsMap.get(playerId)
    // No fixture in this GW = blank gameweek, no points
    if (pred && !predictionsRange?.fixtures?.[pred.team]?.[gw]?.length) return 0

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
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-2xl font-bold tracking-wider uppercase text-foreground mb-2">
              Transfer Planner
            </h2>
            <p className="font-body text-foreground-muted text-sm mb-4">
              Build your squad, then press Find Plans to see optimized suggestions.
            </p>
          </div>
          <button
            onClick={() => setShowHelp(true)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs uppercase tracking-wider text-foreground-muted hover:text-foreground hover:bg-surface-hover"
          >
            <span className="w-5 h-5 border border-current flex items-center justify-center text-[10px] font-bold">
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
            <div className="absolute inset-0 bg-black/60" />
            <div
              className="relative bg-surface border border-border max-w-lg w-full max-h-[80vh] overflow-y-auto"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="p-5 border-b border-border bg-tt-magenta/10">
                <h3 className="text-lg font-bold tracking-wider uppercase text-foreground">
                  How to Use the Planner
                </h3>
              </div>
              <div className="p-5 space-y-5">
                <div>
                  <h4 className="text-sm uppercase tracking-wider text-tt-green mb-2">
                    1. Make Transfers
                  </h4>
                  <p className="text-sm text-foreground-muted">
                    Click any player on the pitch to transfer them out and pick a replacement. Your
                    transfers appear in the sidebar.
                  </p>
                </div>
                <div>
                  <h4 className="text-sm uppercase tracking-wider text-tt-green mb-2">
                    2. Find Plans
                  </h4>
                  <p className="text-sm text-foreground-muted">
                    Press "Find Plans" to run the optimizer. It finds multi-gameweek transfer paths
                    that maximize points, respecting any transfers you've already made.
                  </p>
                </div>
                <div>
                  <h4 className="text-sm uppercase tracking-wider text-tt-green mb-2">
                    3. Select & Save
                  </h4>
                  <p className="text-sm text-foreground-muted">
                    Select a plan to auto-apply its transfers to your squad. Save plans you like for
                    later review.
                  </p>
                </div>
                <div>
                  <h4 className="text-sm uppercase tracking-wider text-tt-green mb-2">
                    Solver Controls
                  </h4>
                  <p className="text-sm text-foreground-muted">
                    <span className="text-foreground font-medium">FT Value</span> controls hit
                    aversion — higher values make the solver prefer rolling transfers.
                    <span className="text-foreground font-medium"> GW Horizon</span> sets how many
                    weeks the solver optimizes over. Solver depth is fixed to
                    <span className="text-foreground font-medium"> Deep</span>.
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
        <div className="grid md:grid-cols-2 gap-4">
          <div className="space-y-2">
            <label className="form-label">Manager ID</label>
            <div className="flex gap-2">
              <FormInput
                type="text"
                value={managerInput}
                onChange={(e) => setManagerInput(e.target.value)}
                placeholder="Enter FPL ID"
                className="flex-1"
                onKeyDown={(e) => e.key === 'Enter' && handleLoadManager()}
              />
              <button onClick={handleLoadManager} className="btn-primary">
                Load
              </button>
            </div>
          </div>

          <div className="space-y-2">
            <label className="form-label">GW Horizon</label>
            <FormSelect
              value={planningHorizon}
              onChange={(e) => setPlanningHorizon(parseInt(e.target.value, 10))}
            >
              {horizonOptions.map((value) => (
                <option key={`horizon-top-${value}`} value={value}>
                  {value} GW{value === 1 ? '' : 's'}
                </option>
              ))}
            </FormSelect>
          </div>
        </div>

        {(squadError || solveError) && (
          <div className="p-4 bg-destructive/10 border border-destructive/30 text-destructive">
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
              />
              <StatPanel
                label={selectedGameweek !== null ? `Bank (GW${selectedGameweek})` : 'Bank'}
                value={`\u00A3${effectiveBank.toFixed(1)}m`}
                highlight={effectiveBank !== optimizeData.current_squad.bank}
              />
              <StatPanel
                label={`Projected (${displayHorizonLength} GWs)`}
                value={`${selectedPath ? selectedPath.total_score.toFixed(1) : (squadPredictions?.total ?? '...')} pts`}
                highlight
              />
            </StatPanelGrid>

            {/* Solver & Recommended Plans */}
            <BroadcastCard title="Recommended Plans" accentColor="magenta">
              {/* Solver controls */}
              <div className="flex items-end gap-2 mb-4">
                <div className="flex items-end gap-2 flex-1 min-w-0">
                  <button
                    onClick={handleFindPlans}
                    disabled={isSolveActive || !managerId || parsedConstraints.hasErrors}
                    className={`btn-primary relative ${isStale ? 'ring-2 ring-tt-yellow/50' : ''}`}
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
              </div>

              {parsedConstraints.hasErrors && (
                <div className="mb-4 border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                  Invalid advanced settings. Open the Advanced Settings card to fix:
                  {' ' + parsedConstraints.errors.join(' | ')}
                </div>
              )}

              {/* Loading skeleton */}
              {isSolveActive && (
                <div className="pt-3 border-t border-border/50">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    {[0, 1, 2].map((i) => (
                      <div key={i} className="p-3 bg-surface-elevated">
                        <div className="h-3 w-16 bg-foreground/10 mb-3" />
                        <div className="h-6 w-24 bg-tt-magenta/10 mb-3" />
                        <div className="space-y-1.5">
                          <div className="h-2.5 w-full bg-foreground/5" />
                          <div className="h-2.5 w-3/4 bg-foreground/5" />
                          <div className="h-2.5 w-5/6 bg-foreground/5" />
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
                        className={`p-3 text-left ${
                          isSelected
                            ? 'bg-tt-magenta/20 border-2 border-tt-magenta ring-2 ring-tt-magenta/30'
                            : 'bg-surface-elevated hover:bg-surface-hover border border-transparent'
                        }`}
                      >
                        <div className="flex items-center justify-between mb-2">
                          <span className="text-xs uppercase tracking-wider text-foreground-muted">
                            Plan {path.id}
                          </span>
                          {path.total_hits > 0 && (
                            <span className="text-xs text-destructive">
                              {path.total_hits} hit{path.total_hits > 1 ? 's' : ''}
                            </span>
                          )}
                        </div>
                        <div
                          className={`text-lg font-bold ${
                            path.score_vs_hold > 0 ? 'text-tt-green' : 'text-foreground'
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
                                  <span className="text-tt-green/80">
                                    [{CHIP_SHORT_LABELS[gwData.chip_played as keyof ChipPlan]}]{' '}
                                  </span>
                                )}
                                {gwData.action === 'bank' ? (
                                  <span className="text-foreground-dim">Roll</span>
                                ) : gwData.chip_played === 'free_hit' &&
                                  gwData.moves.length === 0 ? (
                                  <span className="text-foreground-dim">FH squad optimized</span>
                                ) : (
                                  gwData.moves.map((m, mi) => (
                                    <span key={mi}>
                                      {mi > 0 && ', '}
                                      <span className="text-destructive/70">{m.out_name}</span>
                                      <span className="text-foreground-dim">{' \u2192 '}</span>
                                      <span className="text-tt-green/70">{m.in_name}</span>
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
            </BroadcastCard>

            <div>
              <button
                type="button"
                data-testid="planner-advanced-toggle"
                onClick={() => setShowAdvancedSettings((prev) => !prev)}
                className="w-full flex items-center justify-between p-4 bg-surface-elevated hover:bg-surface-hover"
              >
                <div className="flex items-center gap-3">
                  <h3 className="text-sm font-bold tracking-wider uppercase text-foreground">
                    Advanced Settings
                  </h3>
                  <span className="text-xs text-foreground-muted">
                    solver + chips + constraints
                  </span>
                </div>
                <span
                  className={`text-foreground-muted transition-transform ${showAdvancedSettings ? 'rotate-180' : ''}`}
                >
                  {'\u25BC'}
                </span>
              </button>
              {showAdvancedSettings && (
                <div className="mt-2">
                  <BroadcastCard accentColor="magenta" animate={false}>
                    <div className="space-y-3">
                      <FormSectionCard
                        heading="Solver Settings"
                        description="Deep search is fixed. Tune objective mode and hit aversion."
                      >
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                          <FormField label="Objective">
                            <div className="flex items-center gap-1">
                              {(Object.keys(OBJECTIVE_LABELS) as PlannerObjectiveMode[]).map(
                                (mode) => (
                                  <button
                                    key={mode}
                                    onClick={() => setObjectiveMode(mode)}
                                    className={`px-2 py-1 text-[10px] uppercase tracking-wider ${
                                      objectiveMode === mode
                                        ? 'bg-highlight/20 text-highlight border border-highlight/30'
                                        : 'text-foreground-muted hover:text-foreground hover:bg-surface-hover'
                                    }`}
                                  >
                                    {OBJECTIVE_LABELS[mode]}
                                  </button>
                                )
                              )}
                            </div>
                          </FormField>
                          <FormField label="FT Value">
                            <div className="flex items-center gap-3">
                              <input
                                type="range"
                                min="0"
                                max="5"
                                step="0.5"
                                value={ftValue}
                                onChange={(e) => setFtValue(parseFloat(e.target.value))}
                                className="flex-1 accent-tt-magenta"
                              />
                              <span className="text-sm text-foreground w-10 text-right">
                                {ftValue.toFixed(1)}
                              </span>
                            </div>
                          </FormField>
                          <FormField label="Free Transfers">
                            <FormSelect
                              value={freeTransfers ?? effectiveFt}
                              onChange={(e) => setFreeTransfers(parseInt(e.target.value, 10))}
                            >
                              {[1, 2, 3, 4, 5].map((n) => (
                                <option key={`advanced-ft-${n}`} value={n}>
                                  {n} FT
                                </option>
                              ))}
                            </FormSelect>
                          </FormField>
                        </div>
                      </FormSectionCard>

                      <FormSectionCard
                        heading="Chip Weeks"
                        description="Pick exact chip weeks. Auto chip-planning is disabled."
                      >
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                          {(Object.keys(CHIP_LABELS) as (keyof ChipPlan)[]).map((chip) => (
                            <FormField key={chip} label={CHIP_LABELS[chip]}>
                              <FormSelect
                                value={chipPlan[chip] ?? ''}
                                onChange={(e) => setChipWeek(chip, e.target.value)}
                              >
                                <option value="">Not set</option>
                                {optimizeData.planning_horizon.map((gw) => (
                                  <option key={`${chip}-${gw}`} value={gw}>
                                    GW{gw}
                                  </option>
                                ))}
                              </FormSelect>
                            </FormField>
                          ))}
                        </div>
                      </FormSectionCard>

                      <FormSectionCard heading="Constraints">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                          <FormField label="Lock Players">
                            <FormInput
                              data-testid="constraints-lock-search"
                              value={lockSearch}
                              onChange={(e) => setLockSearch(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter' && lockSearchResults.length > 0) {
                                  e.preventDefault()
                                  addLockPlayer(lockSearchResults[0].id)
                                }
                              }}
                              placeholder="Search by name..."
                            />
                            {lockSearch.trim().length > 0 && lockSearchResults.length > 0 && (
                              <SearchResultsList>
                                {lockSearchResults.map((player) => (
                                  <SearchResultButton
                                    key={`lock-option-${player.id}`}
                                    data-testid={`constraints-lock-option-${player.id}`}
                                    onClick={() => addLockPlayer(player.id)}
                                  >
                                    <span className="text-foreground">{player.web_name}</span>
                                    <span className="text-foreground-muted">
                                      {teamsMap.get(player.team) ?? '?'}
                                    </span>
                                  </SearchResultButton>
                                ))}
                              </SearchResultsList>
                            )}
                            <SelectionPillList
                              emptyText="No locked players"
                              hasItems={lockIds.length > 0}
                            >
                              {lockIds.map((id) => {
                                const player = playersById.get(id)
                                return (
                                  <SelectionPill
                                    key={`lock-selected-${id}`}
                                    tone="lock"
                                    onClick={() => removeLockPlayer(id)}
                                    title="Remove lock"
                                  >
                                    <span>{player?.web_name ?? `#${id}`}</span>
                                    <span className="text-tt-green/80">×</span>
                                  </SelectionPill>
                                )
                              })}
                            </SelectionPillList>
                          </FormField>

                          <FormField label="Avoid Players">
                            <FormInput
                              data-testid="constraints-avoid-search"
                              value={avoidSearch}
                              onChange={(e) => setAvoidSearch(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter' && avoidSearchResults.length > 0) {
                                  e.preventDefault()
                                  addAvoidPlayer(avoidSearchResults[0].id)
                                }
                              }}
                              placeholder="Search by name..."
                            />
                            {avoidSearch.trim().length > 0 && avoidSearchResults.length > 0 && (
                              <SearchResultsList>
                                {avoidSearchResults.map((player) => (
                                  <SearchResultButton
                                    key={`avoid-option-${player.id}`}
                                    data-testid={`constraints-avoid-option-${player.id}`}
                                    onClick={() => addAvoidPlayer(player.id)}
                                  >
                                    <span className="text-foreground">{player.web_name}</span>
                                    <span className="text-foreground-muted">
                                      {teamsMap.get(player.team) ?? '?'}
                                    </span>
                                  </SearchResultButton>
                                ))}
                              </SearchResultsList>
                            )}
                            <SelectionPillList
                              emptyText="No avoided players"
                              hasItems={avoidIds.length > 0}
                            >
                              {avoidIds.map((id) => {
                                const player = playersById.get(id)
                                return (
                                  <SelectionPill
                                    key={`avoid-selected-${id}`}
                                    tone="avoid"
                                    onClick={() => removeAvoidPlayer(id)}
                                    title="Remove avoid"
                                  >
                                    <span>{player?.web_name ?? `#${id}`}</span>
                                    <span className="text-destructive/80">×</span>
                                  </SelectionPill>
                                )
                              })}
                            </SelectionPillList>
                          </FormField>

                          <FormField label="Avoid Teams">
                            <FormInput
                              data-testid="constraints-avoid-team-search"
                              value={avoidTeamSearch}
                              onChange={(e) => setAvoidTeamSearch(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter' && avoidTeamSearchResults.length > 0) {
                                  e.preventDefault()
                                  addAvoidTeam(avoidTeamSearchResults[0].id)
                                }
                              }}
                              placeholder="Search by team name..."
                            />
                            {avoidTeamSearch.trim().length > 0 &&
                              avoidTeamSearchResults.length > 0 && (
                                <SearchResultsList>
                                  {avoidTeamSearchResults.map((team) => (
                                    <SearchResultButton
                                      key={`avoid-team-option-${team.id}`}
                                      data-testid={`constraints-avoid-team-option-${team.id}`}
                                      onClick={() => addAvoidTeam(team.id)}
                                    >
                                      <span className="text-foreground">{team.name}</span>
                                      <span className="text-foreground-muted">
                                        {team.short_name}
                                      </span>
                                    </SearchResultButton>
                                  ))}
                                </SearchResultsList>
                              )}
                            <SelectionPillList
                              emptyText="No avoided teams"
                              hasItems={avoidTeamIds.length > 0}
                            >
                              {avoidTeamIds.map((id) => {
                                const team = teamsById.get(id)
                                return (
                                  <SelectionPill
                                    key={`avoid-team-selected-${id}`}
                                    tone="team"
                                    onClick={() => removeAvoidTeam(id)}
                                    title="Remove avoid team"
                                  >
                                    <span>{team?.short_name ?? `#${id}`}</span>
                                    <span className="text-highlight/80">×</span>
                                  </SelectionPill>
                                )
                              })}
                            </SelectionPillList>
                          </FormField>
                        </div>
                        {parsedConstraints.hasErrors && (
                          <div className="mt-2 form-error-text">
                            {parsedConstraints.errors.join(' | ')}
                          </div>
                        )}
                      </FormSectionCard>
                    </div>
                  </BroadcastCard>
                </div>
              )}
            </div>

            {/* Squad Formation */}
            <BroadcastCard
              title={`Your Squad \u2014 GW${selectedGameweek ?? '?'} Predictions`}
              accentColor="cyan"
            >
              <div className="mb-4 space-y-3">
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
                          px-4 py-3                          ${
                            isSelected
                              ? 'bg-tt-green/20 border-2 border-tt-green ring-2 ring-tt-cyan/30'
                              : isFirst && hitsCost > 0 && !selectedPath
                                ? 'bg-destructive/20 border border-destructive/30 hover:bg-destructive/30'
                                : pathAction === 'transfer'
                                  ? 'bg-tt-magenta/10 border border-tt-magenta/20 hover:bg-tt-magenta/20'
                                  : gwUserTransfers.length > 0
                                    ? 'bg-tt-green/10 border border-tt-green/20 hover:bg-tt-green/20'
                                    : 'bg-surface-elevated hover:bg-surface-hover border border-transparent'
                          }
                        `}
                      >
                        <div
                          className={`text-xs uppercase ${isSelected ? 'text-tt-green' : 'text-foreground-muted'}`}
                        >
                          GW{gw}
                          {!selectedPath && isFirst && hitsCost > 0 && ` (-${hitsCost})`}
                          {pathGw?.hit_cost ? ` (-${pathGw.hit_cost})` : ''}
                        </div>
                        <div
                          className={`text-xl font-bold ${isSelected ? 'text-tt-green' : 'text-foreground'}`}
                        >
                          {pts.toFixed(1)}
                        </div>
                        {pathAction && (
                          <div className="text-[10px] text-tt-magenta mt-0.5">
                            {pathGw?.chip_played
                              ? CHIP_LABELS[pathGw.chip_played as keyof ChipPlan]
                              : pathAction === 'bank'
                                ? 'Roll'
                                : `${pathGw.moves.length} move${pathGw.moves.length > 1 ? 's' : ''}`}
                          </div>
                        )}
                        {!selectedPath && gwUserTransfers.length > 0 && (
                          <div className="text-[10px] text-tt-green mt-0.5">
                            {gwUserTransfers.length} transfer
                            {gwUserTransfers.length > 1 ? 's' : ''}
                          </div>
                        )}
                      </button>
                    )
                  })}
                </div>
                {selectedGameweek !== null && selectedGwTransferStatus && (
                  <div className="border border-border/60 bg-surface-elevated/60 px-3 py-2 flex items-center justify-between">
                    <span className="text-xs text-foreground-muted">
                      GW{selectedGameweek} transfer budget
                    </span>
                    <span
                      className={`text-sm ${
                        selectedGwTransferStatus.hits > 0 ? 'text-destructive' : 'text-foreground'
                      }`}
                    >
                      {selectedGwTransferStatus.statusLabel}
                      <span className="text-foreground-muted">
                        {' '}
                        ({selectedGwTransferStatus.used}
                        {selectedGwTransferStatus.hits > 0
                          ? ` used, ${selectedGwTransferStatus.available} free`
                          : `/${selectedGwTransferStatus.available} used`}
                        )
                      </span>
                    </span>
                  </div>
                )}
                <div className="pt-3 border-t border-border/50 flex items-center justify-between">
                  <span className="text-xs text-foreground-muted">
                    Total ({displayHorizonLength} GWs)
                    {selectedPath && ' \u2014 Plan ' + selectedPath.id}
                  </span>
                  <span className="font-bold text-tt-green text-lg">
                    {selectedPath
                      ? `${selectedPath.total_score.toFixed(1)} pts`
                      : `${squadPredictions?.total.toFixed(1) ?? '...'} pts`}
                  </span>
                </div>
              </div>
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
              <BroadcastCard title="Your Transfers" accentColor="cyan">
                <div className="flex flex-wrap gap-2 items-center">
                  {Object.entries(transfersByGw)
                    .sort(([a], [b]) => Number(a) - Number(b))
                    .map(([gwStr, transfers]) => (
                      <div key={gwStr} className="flex items-center gap-2">
                        <span className="text-[10px] uppercase tracking-wider text-foreground-muted">
                          GW{gwStr}:
                        </span>
                        {transfers.map((ft, idx) => {
                          const outPlayer = playersData?.players.find((p) => p.id === ft.out)
                          const inPlayer = playersData?.players.find((p) => p.id === ft.in)
                          return (
                            <div
                              key={idx}
                              className="flex items-center gap-1.5 px-2 py-1 bg-surface-elevated text-sm"
                            >
                              <span className="text-destructive truncate max-w-[80px]">
                                {outPlayer?.web_name ?? `#${ft.out}`}
                              </span>
                              <span className="text-foreground-dim">{'\u2192'}</span>
                              <span className="text-tt-green truncate max-w-[80px]">
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
              <BroadcastCard title="Saved Plans" accentColor="magenta">
                <div className="flex flex-wrap gap-2">
                  {savedPlans.map((plan) => (
                    <div
                      key={plan.id}
                      className="flex items-center gap-3 px-3 py-2 bg-surface-elevated rounded-lg"
                    >
                      <span className="text-xs uppercase tracking-wider text-foreground">
                        {plan.name}
                      </span>
                      <span
                        className={`text-sm font-bold ${plan.scoreVsHold > 0 ? 'text-tt-green' : 'text-foreground'}`}
                      >
                        {plan.scoreVsHold > 0 ? '+' : ''}
                        {plan.scoreVsHold.toFixed(1)}
                      </span>
                      <span className="text-xs text-foreground-muted">
                        {plan.transfers.length} transfer{plan.transfers.length !== 1 ? 's' : ''}
                      </span>
                      <button
                        onClick={() => handleLoadSavedPlan(plan)}
                        className="text-xs text-tt-green hover:text-tt-green/80"
                      >
                        Load
                      </button>
                      <button
                        onClick={() => handleDeleteSavedPlan(plan.id)}
                        className="text-xs text-foreground-muted hover:text-destructive"
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
          <div>
            <button
              onClick={() => setIsExplorerExpanded((prev) => !prev)}
              className="w-full flex items-center justify-between p-4 bg-surface-elevated hover:bg-surface-hover"
            >
              <div className="flex items-center gap-3">
                <h3 className="text-sm font-bold tracking-wider uppercase text-foreground">
                  Player Explorer
                </h3>
                <span className="text-xs text-foreground-muted">
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
            className="absolute inset-0 bg-black/40"
            onClick={() => {
              setSelectedPlayer(null)
              setReplacementSearch('')
            }}
          />

          {/* Drawer panel */}
          <div className="absolute top-0 right-0 h-full w-full max-w-sm bg-surface border-l border-tt-green/20 flex flex-col animate-drawer-slide-in">
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
