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
