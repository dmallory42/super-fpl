export interface Fixture {
  id: number
  event: number | null // gameweek number
  team_h: number // home team id
  team_a: number // away team id
  team_h_score: number | null
  team_a_score: number | null
  kickoff_time: string | null
  finished: boolean
  started: boolean
  finished_provisional: boolean
  minutes: number
  provisional_start_time: boolean
  team_h_difficulty: number
  team_a_difficulty: number
}

export interface FixtureWithTeamNames extends Fixture {
  team_h_name: string
  team_a_name: string
  team_h_short_name: string
  team_a_short_name: string
}
