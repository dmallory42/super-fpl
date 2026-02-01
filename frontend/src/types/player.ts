export interface Player {
  id: number
  first_name: string
  second_name: string
  web_name: string
  team: number
  team_code: number
  element_type: number // 1=GKP, 2=DEF, 3=MID, 4=FWD
  now_cost: number // price in tenths (e.g., 100 = 10.0)
  total_points: number
  points_per_game: string
  form: string
  selected_by_percent: string
  minutes: number
  goals_scored: number
  assists: number
  clean_sheets: number
  goals_conceded: number
  yellow_cards: number
  red_cards: number
  saves: number
  bonus: number
  bps: number
  influence: string
  creativity: string
  threat: string
  ict_index: string
  status: string // 'a' = available, 'i' = injured, etc.
  news: string
  news_added: string | null
  chance_of_playing_next_round: number | null
  chance_of_playing_this_round: number | null
}

export type Position = 'GKP' | 'DEF' | 'MID' | 'FWD'

export const positionMap: Record<number, Position> = {
  1: 'GKP',
  2: 'DEF',
  3: 'MID',
  4: 'FWD',
}

export function getPositionName(elementType: number): Position {
  return positionMap[elementType] || 'MID'
}

export function formatPrice(nowCost: number): string {
  return (nowCost / 10).toFixed(1)
}
