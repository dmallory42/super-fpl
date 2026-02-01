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
  }
  fixture?: {
    opponent: number
    is_home: boolean
    difficulty: number
  }
}

export interface PredictionsResponse {
  gameweek: number
  predictions: PlayerPrediction[]
  generated_at: string
}

export async function fetchPredictions(gameweek: number): Promise<PredictionsResponse> {
  return fetchApi<PredictionsResponse>(`/predictions/${gameweek}`)
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
}

export interface LiveElement {
  id: number
  stats: LivePlayerStats
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
