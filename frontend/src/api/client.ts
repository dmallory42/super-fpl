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
