const API_BASE = '/api'

export interface ApiResponse<T> {
  data: T
  error?: string
}

async function fetchApi<T>(endpoint: string): Promise<T> {
  const response = await fetch(`${API_BASE}${endpoint}`)

  if (!response.ok) {
    throw new Error(`API error: ${response.status} ${response.statusText}`)
  }

  return response.json()
}

export interface SyncStatusResponse {
  last_sync: number
}

export async function fetchSyncStatus(): Promise<SyncStatusResponse> {
  return fetchApi<SyncStatusResponse>('/sync/status')
}

export interface PlayersResponse {
  players: import('../types').Player[]
  teams: { id: number; name: string; short_name: string }[]
}

export interface FixturesResponse {
  fixtures: import('../types').Fixture[]
}

export async function fetchPlayers(): Promise<PlayersResponse> {
  return fetchApi<PlayersResponse>('/players')
}

export async function fetchFixtures(gameweek?: number): Promise<FixturesResponse> {
  const endpoint = gameweek ? `/fixtures?gw=${gameweek}` : '/fixtures'
  return fetchApi<FixturesResponse>(endpoint)
}

export async function fetchManager(id: number): Promise<import('../types').Manager> {
  return fetchApi<import('../types').Manager>(`/managers/${id}`)
}

export async function fetchManagerPicks(id: number, gameweek: number): Promise<import('../types').ManagerPicks> {
  return fetchApi<import('../types').ManagerPicks>(`/managers/${id}/picks/${gameweek}`)
}

export interface ManagerHistoryResponse {
  current: import('../types').EntryHistory[]
  past?: { season_name: string; total_points: number; rank: number }[]
  chips?: { name: string; time: string; event: number }[]
}

export async function fetchManagerHistory(id: number): Promise<ManagerHistoryResponse> {
  return fetchApi<ManagerHistoryResponse>(`/managers/${id}/history`)
}

export interface TeamsResponse {
  teams: { id: number; name: string; short_name: string }[]
}

export async function fetchTeams(): Promise<TeamsResponse> {
  return fetchApi<TeamsResponse>('/teams')
}

export interface PlayerPrediction {
  player_id: number
  web_name: string
  team: number
  position: number
  now_cost: number
  form: string | number
  total_points: number
  predicted_points: number
  confidence: number
  breakdown?: {
    appearance: number
    goals: number
    assists: number
    clean_sheet: number
    bonus: number
    goals_conceded: number
    saves: number
    defensive_contribution: number
    cards: number
  }
  fixture?: {
    opponent: number
    is_home: boolean
    difficulty: number
  }
  availability?: 'available' | 'unavailable' | 'injured' | 'doubtful' | 'suspended'
}

export interface PredictionsResponse {
  gameweek: number
  predictions: PlayerPrediction[]
  generated_at: string
  source?: 'snapshot'
}

export async function fetchPredictions(gameweek?: number): Promise<PredictionsResponse> {
  // If no gameweek specified, fetch current (next upcoming)
  const gw = gameweek ?? 'next'
  return fetchApi<PredictionsResponse>(`/predictions/${gw}`)
}

// Multi-gameweek predictions for player comparison
export interface PlayerMultiWeekPrediction {
  player_id: number
  web_name: string
  team: number
  position: number
  now_cost: number
  form: number
  total_points: number
  expected_mins: Record<number, number> // per-GW expected minutes from prediction engine
  expected_mins_if_fit: number // expected minutes when fully available (single value)
  predictions: Record<number, number> // gameweek -> predicted points
  if_fit_predictions: Record<number, number> // gameweek -> if-fit points (availability=1.0)
  total_predicted: number
}

export interface FixtureOpponent {
  opponent: string
  is_home: boolean
}

export interface PredictionsRangeResponse {
  gameweeks: number[]
  current_gameweek: number
  players: PlayerMultiWeekPrediction[]
  fixtures: Record<number, Record<number, FixtureOpponent[]>>
  generated_at: string
}

export async function fetchPredictionsRange(startGw?: number, endGw?: number): Promise<PredictionsRangeResponse> {
  const params = new URLSearchParams()
  if (startGw) params.set('start', String(startGw))
  if (endGw) params.set('end', String(endGw))
  const query = params.toString()
  return fetchApi<PredictionsRangeResponse>(`/predictions/range${query ? `?${query}` : ''}`)
}

export interface LeagueStanding {
  entry: number
  player_name: string
  entry_name: string
  rank: number
  total: number
}

export interface LeagueResponse {
  league: {
    id: number
    name: string
    type: string
  }
  standings: {
    results: LeagueStanding[]
    has_next?: boolean
  }
}

export async function fetchLeague(id: number, page = 1): Promise<LeagueResponse> {
  return fetchApi<LeagueResponse>(`/leagues/${id}?page=${page}`)
}

export interface ComparisonPlayer {
  id: number
  web_name: string
  team: number
  position: number
  now_cost: number
  total_points: number
}

export interface Differential {
  player_id: number
  eo: number
  is_captain: boolean
  multiplier: number
}

export interface RiskScore {
  score: number
  level: 'low' | 'medium' | 'high'
  breakdown: {
    captain_risk: number
    playing_count: number
  }
}

export interface ComparisonResponse {
  gameweek: number
  manager_count: number
  effective_ownership: Record<number, number>
  differentials: Record<number, Differential[]>
  risk_scores: Record<number, RiskScore>
  ownership_matrix: Record<number, Record<number, number>>
  players: Record<number, ComparisonPlayer>
}

export async function fetchComparison(managerIds: number[], gameweek?: number): Promise<ComparisonResponse> {
  const idsParam = managerIds.join(',')
  const gwParam = gameweek ? `&gw=${gameweek}` : ''
  return fetchApi<ComparisonResponse>(`/compare?ids=${idsParam}${gwParam}`)
}

// Live data types
export interface LivePlayerStats {
  minutes: number
  goals_scored: number
  assists: number
  clean_sheets: number
  goals_conceded: number
  own_goals: number
  penalties_saved: number
  penalties_missed: number
  yellow_cards: number
  red_cards: number
  saves: number
  bonus: number
  bps: number
  total_points: number
  clearances_blocks_interceptions?: number
  defensive_contribution?: number
}

export interface ExplainStat {
  identifier: string
  points: number
  value: number
  points_modification: number
}

export interface ExplainEntry {
  fixture: number
  stats: ExplainStat[]
}

export interface LiveElement {
  id: number
  stats: LivePlayerStats
  explain?: ExplainEntry[]
  web_name?: string
  team?: number
  position?: number
}

export interface LiveDataResponse {
  elements: LiveElement[]
}

export interface LiveManagerPlayer {
  player_id: number
  position: number
  multiplier: number
  points: number
  effective_points: number
  stats: LivePlayerStats | null
  is_playing: boolean
  is_captain: boolean
}

export interface LiveManagerResponse {
  manager_id: number
  gameweek: number
  total_points: number
  bench_points: number
  players: LiveManagerPlayer[]
  overall_rank: number | null
  pre_gw_rank: number | null
  updated_at: string
  error?: string
}

export interface BonusPrediction {
  player_id: number
  bps: number
  predicted_bonus: number
  fixture_id: number
}

export interface LiveBonusResponse {
  gameweek: number
  bonus_predictions: BonusPrediction[]
}

export async function fetchLiveData(gameweek: number): Promise<LiveDataResponse> {
  return fetchApi<LiveDataResponse>(`/live/${gameweek}`)
}

export async function fetchLiveManager(gameweek: number, managerId: number): Promise<LiveManagerResponse> {
  return fetchApi<LiveManagerResponse>(`/live/${gameweek}/manager/${managerId}`)
}

export async function fetchLiveBonus(gameweek: number): Promise<LiveBonusResponse> {
  return fetchApi<LiveBonusResponse>(`/live/${gameweek}/bonus`)
}

// Live samples/EO types
export interface TierSampleData {
  avg_points: number
  sample_size: number
  effective_ownership: Record<number, number>
  captain_percent?: Record<number, number>
  estimated?: boolean // True if using FPL average estimates instead of real samples
}

export interface LiveSamplesResponse {
  gameweek: number
  samples: {
    top_10k?: TierSampleData
    top_100k?: TierSampleData
    top_1m?: TierSampleData
    overall?: TierSampleData
  }
  is_estimated?: boolean // True if no real samples exist
  updated_at: string
}

export async function fetchLiveSamples(gameweek: number): Promise<LiveSamplesResponse> {
  return fetchApi<LiveSamplesResponse>(`/live/${gameweek}/samples`)
}

// Fixtures status types
export interface GameweekFixtureStatus {
  gameweek: number
  fixtures: Array<{
    id: number
    kickoff_time: string
    started: boolean
    finished: boolean
    minutes: number
    home_club_id: number
    away_club_id: number
    home_score: number | null
    away_score: number | null
  }>
  total: number
  started: number
  finished: number
  first_kickoff: string
  last_kickoff: string
}

export interface FixturesStatusResponse {
  current_gameweek: number
  is_live: boolean
  gameweeks: GameweekFixtureStatus[]
}

export async function fetchFixturesStatus(): Promise<FixturesStatusResponse> {
  return fetchApi<FixturesStatusResponse>('/fixtures/status')
}

// Transfer planner types
export interface TransferOutPlayer {
  player_id: number
  web_name: string
  team: number
  position: number
  selling_price: number
  predicted_points: number
  reason: string
}

export interface TransferInPlayer {
  player_id: number
  web_name: string
  team: number
  position: number
  now_cost: number
  predicted_points: number
  form: number
  total_points: number
}

export interface TransferSuggestion {
  out: TransferOutPlayer
  in: TransferInPlayer[]
}

export interface TransferSuggestResponse {
  manager_id: number
  gameweek: number
  bank: number
  squad_value: number
  free_transfers: number
  suggestions: TransferSuggestion[]
  squad_analysis: {
    total_predicted_points: number
    weakest_players: Array<{
      player_id: number
      web_name: string
      predicted_points: number
      form: number
    }>
  }
  error?: string
}

export interface TransferSimulateResponse {
  transfer_out: {
    player_id: number
    web_name: string
    predicted_points: number
    now_cost: number
  }
  transfer_in: {
    player_id: number
    web_name: string
    predicted_points: number
    now_cost: number
  }
  points_difference: number
  current_squad_total: number
  new_squad_total: number
  cost_difference: number
}

export interface TransferTarget {
  player_id: number
  web_name: string
  team: number
  position: number
  now_cost: number
  predicted_points: number
  form: number
  value_score: number
}

export interface TransferTargetsResponse {
  gameweek: number
  targets: TransferTarget[]
}

export async function fetchTransferSuggestions(
  managerId: number,
  gameweek?: number,
  transfers = 1
): Promise<TransferSuggestResponse> {
  const gwParam = gameweek ? `&gw=${gameweek}` : ''
  return fetchApi<TransferSuggestResponse>(`/transfers/suggest?manager=${managerId}${gwParam}&transfers=${transfers}`)
}

export async function fetchTransferSimulate(
  managerId: number,
  outPlayerId: number,
  inPlayerId: number,
  gameweek?: number
): Promise<TransferSimulateResponse> {
  const gwParam = gameweek ? `&gw=${gameweek}` : ''
  return fetchApi<TransferSimulateResponse>(
    `/transfers/simulate?manager=${managerId}&out=${outPlayerId}&in=${inPlayerId}${gwParam}`
  )
}

export async function fetchTransferTargets(
  gameweek?: number,
  position?: number,
  maxPrice?: number
): Promise<TransferTargetsResponse> {
  const params = new URLSearchParams()
  if (gameweek) params.set('gw', String(gameweek))
  if (position) params.set('position', String(position))
  if (maxPrice) params.set('max_price', String(maxPrice))
  const query = params.toString()
  return fetchApi<TransferTargetsResponse>(`/transfers/targets${query ? `?${query}` : ''}`)
}

// League Analysis
export interface LeagueAnalysisManager {
  id: number
  name: string
  team_name: string
  rank: number
  total: number
}

export interface LeagueAnalysisResponse {
  league: {
    id: number
    name: string
  }
  gameweek: number
  managers: LeagueAnalysisManager[]
  comparison: ComparisonResponse
}

export async function fetchLeagueAnalysis(leagueId: number, gameweek?: number): Promise<LeagueAnalysisResponse> {
  const gwParam = gameweek ? `?gw=${gameweek}` : ''
  return fetchApi<LeagueAnalysisResponse>(`/leagues/${leagueId}/analysis${gwParam}`)
}

// Planner Optimization
export interface ChipPlan {
  wildcard?: number
  bench_boost?: number
  free_hit?: number
  triple_captain?: number
}

export interface TransferRecommendation {
  out: { id: number; web_name: string; team: number; price: number; total_predicted: number }
  in: { id: number; web_name: string; team: number; price: number; total_predicted: number }
  predicted_gain: number
  is_free: boolean
  hit_cost: number
  net_gain: number
  recommended: boolean
}

export interface ChipSuggestion {
  gameweek: number
  estimated_value: number
  has_dgw: boolean
  reason?: string
}

export interface FormationPlayer {
  player_id: number
  web_name: string
  element_type: number
  team: number
  position: number
  predicted_points: number
  expected_mins: number
  multiplier: number
  is_captain: boolean
  is_vice_captain: boolean
  now_cost: number
}

export interface CaptainCandidate {
  player_id: number
  predicted_points: number
  margin: number
}

export interface FormationData {
  gameweek: number
  players: FormationPlayer[]
  starting_total: number
  bench_total: number
  captain_id: number
  vice_captain_id: number
  captain_candidates?: CaptainCandidate[]
}

export interface TransferMove {
  out_id: number
  out_name: string
  out_team: number
  out_price: number
  in_id: number
  in_name: string
  in_team: number
  in_price: number
  gain: number
  is_free: boolean
}

export interface PathGameweek {
  action: 'bank' | 'transfer'
  ft_available: number
  ft_after: number
  moves: TransferMove[]
  hit_cost: number
  gw_score: number
  squad_ids: number[]
  bank: number
}

export interface TransferPath {
  id: number
  total_score: number
  score_vs_hold: number
  total_hits: number
  transfers_by_gw: Record<number, PathGameweek>
}

export type SolverDepth = 'quick' | 'standard' | 'deep'

// xMins overrides: uniform (number) or per-GW (Record<number, number>)
export type XMinsOverrides = Record<number, number | Record<number, number>>

export interface FixedTransfer {
  gameweek: number
  out: number
  in: number
}

export interface PlannerOptimizeResponse {
  current_gameweek: number
  planning_horizon: number[]
  current_squad: {
    player_ids: number[]
    bank: number
    squad_value: number
    free_transfers: number
    api_free_transfers: number
    predicted_points: Record<number | 'total', number>
    formations?: Record<number, FormationData>
  }
  dgw_teams: Record<number, number[]>
  recommendations: TransferRecommendation[]
  chip_suggestions: Record<string, ChipSuggestion>
  chip_plan: ChipPlan
  paths: TransferPath[]
}

// Penalty takers
export interface PenaltyTaker {
  id: number
  web_name: string
  team: number
  position: number
  penalty_order: number
  team_name: string
  team_short: string
}

export interface PenaltyTakersResponse {
  penalty_takers: PenaltyTaker[]
}

export async function fetchPenaltyTakers(): Promise<PenaltyTakersResponse> {
  return fetchApi<PenaltyTakersResponse>('/penalty-takers')
}

export async function setPenaltyOrder(playerId: number, order: number): Promise<void> {
  await fetch(`${API_BASE}/players/${playerId}/penalty-order`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ penalty_order: order }),
  })
}

export async function clearPenaltyOrder(playerId: number): Promise<void> {
  await fetch(`${API_BASE}/players/${playerId}/penalty-order`, { method: 'DELETE' })
}

// xMins overrides
export async function setXMins(playerId: number, mins: number): Promise<void> {
  await fetch(`${API_BASE}/players/${playerId}/xmins`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ expected_mins: mins }),
  })
}

export async function clearXMins(playerId: number): Promise<void> {
  await fetch(`${API_BASE}/players/${playerId}/xmins`, { method: 'DELETE' })
}

export async function fetchPlannerOptimize(
  managerId: number,
  freeTransfers: number | null = null,
  chipPlan: ChipPlan = {},
  xMinsOverrides: XMinsOverrides = {},
  fixedTransfers: FixedTransfer[] = [],
  ftValue: number = 1.5,
  depth: SolverDepth = 'standard',
  skipSolve: boolean = false,
): Promise<PlannerOptimizeResponse> {
  const params = new URLSearchParams()
  params.set('manager', String(managerId))
  // Only send ft when user explicitly overrides; null = auto-detect from API
  if (freeTransfers !== null) {
    params.set('ft', String(freeTransfers))
  }

  if (chipPlan.wildcard) params.set('wildcard_gw', String(chipPlan.wildcard))
  if (chipPlan.bench_boost) params.set('bench_boost_gw', String(chipPlan.bench_boost))
  if (chipPlan.free_hit) params.set('free_hit_gw', String(chipPlan.free_hit))
  if (chipPlan.triple_captain) params.set('triple_captain_gw', String(chipPlan.triple_captain))

  // Pass xMins overrides as JSON if any exist
  if (Object.keys(xMinsOverrides).length > 0) {
    params.set('xmins', JSON.stringify(xMinsOverrides))
  }

  // Path solver params
  if (fixedTransfers.length > 0) {
    params.set('fixed_transfers', JSON.stringify(fixedTransfers))
  }
  if (ftValue !== 1.5) {
    params.set('ft_value', String(ftValue))
  }
  if (depth !== 'standard') {
    params.set('depth', depth)
  }
  if (skipSolve) {
    params.set('skip_solve', '1')
  }

  return fetchApi<PlannerOptimizeResponse>(`/planner/optimize?${params.toString()}`)
}
