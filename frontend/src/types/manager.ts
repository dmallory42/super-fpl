export interface Manager {
  id: number
  joined_time: string
  started_event: number
  favourite_team: number | null
  player_first_name: string
  player_last_name: string
  player_region_id: number
  player_region_name: string
  player_region_iso_code_short: string
  player_region_iso_code_long: string
  summary_overall_points: number
  summary_overall_rank: number | null
  summary_event_points: number
  summary_event_rank: number | null
  current_event: number
  leagues: ManagerLeagues
  name: string
  name_change_blocked: boolean
  kit: string | null
  last_deadline_bank: number
  last_deadline_value: number
  last_deadline_total_transfers: number
}

export interface ManagerLeagues {
  classic: LeagueEntry[]
  h2h: LeagueEntry[]
}

export interface LeagueEntry {
  id: number
  name: string
  short_name: string | null
  created: string
  closed: boolean
  rank: number | null
  max_entries: number | null
  league_type: string
  scoring: string
  admin_entry: number | null
  start_event: number
  entry_can_leave: boolean
  entry_can_admin: boolean
  entry_can_invite: boolean
  has_cup: boolean
  cup_league: number | null
  cup_qualified: boolean | null
  entry_rank: number
  entry_last_rank: number
}

export interface Pick {
  element: number // player id
  position: number // 1-15 (1-11 = starting, 12-15 = bench)
  multiplier: number // 0 = benched, 1 = normal, 2 = captain, 3 = triple captain
  is_captain: boolean
  is_vice_captain: boolean
}

export interface ManagerPicks {
  active_chip: string | null
  automatic_subs: AutomaticSub[]
  entry_history: EntryHistory
  picks: Pick[]
}

export interface AutomaticSub {
  entry: number
  element_in: number
  element_out: number
  event: number
}

export interface EntryHistory {
  event: number
  points: number
  total_points: number
  rank: number | null
  rank_sort: number | null
  overall_rank: number
  bank: number
  value: number
  event_transfers: number
  event_transfers_cost: number
  points_on_bench: number
}
