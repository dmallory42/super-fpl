import { describe, expect, it } from 'vitest'
import type { SeasonPlayerPoints } from '../api/client'
import type { Player } from '../types'
import { buildSeasonBestSquad } from './seasonBestSquad'

function createPlayer(id: number, elementType: number): Player {
  return {
    id,
    first_name: `P${id}`,
    second_name: `S${id}`,
    web_name: `Player${id}`,
    team: id,
    team_code: id,
    element_type: elementType,
    now_cost: 50,
    total_points: 0,
    points_per_game: '0.0',
    form: '0.0',
    selected_by_percent: '0.0',
    minutes: 0,
    goals_scored: 0,
    assists: 0,
    clean_sheets: 0,
    goals_conceded: 0,
    yellow_cards: 0,
    red_cards: 0,
    saves: 0,
    bonus: 0,
    bps: 0,
    influence: '0.0',
    creativity: '0.0',
    threat: '0.0',
    ict_index: '0.0',
    status: 'a',
    news: '',
    news_added: null,
    chance_of_playing_next_round: null,
    chance_of_playing_this_round: null,
  }
}

describe('buildSeasonBestSquad', () => {
  it('builds a valid 15-man best squad and formation from owned points', () => {
    const players = new Map<number, Player>([
      [1, createPlayer(1, 1)],
      [2, createPlayer(2, 1)],
      [3, createPlayer(3, 2)],
      [4, createPlayer(4, 2)],
      [5, createPlayer(5, 2)],
      [6, createPlayer(6, 2)],
      [7, createPlayer(7, 2)],
      [8, createPlayer(8, 3)],
      [9, createPlayer(9, 3)],
      [10, createPlayer(10, 3)],
      [11, createPlayer(11, 3)],
      [12, createPlayer(12, 3)],
      [13, createPlayer(13, 4)],
      [14, createPlayer(14, 4)],
      [15, createPlayer(15, 4)],
    ])

    const seasonPlayerPoints: SeasonPlayerPoints[] = [
      { player_id: 1, owned_points: 85, contributed_points: 80, owned_gameweeks: 20 },
      { player_id: 2, owned_points: 70, contributed_points: 62, owned_gameweeks: 18 },
      { player_id: 3, owned_points: 110, contributed_points: 101, owned_gameweeks: 28 },
      { player_id: 4, owned_points: 95, contributed_points: 90, owned_gameweeks: 25 },
      { player_id: 5, owned_points: 90, contributed_points: 85, owned_gameweeks: 24 },
      { player_id: 6, owned_points: 75, contributed_points: 70, owned_gameweeks: 18 },
      { player_id: 7, owned_points: 65, contributed_points: 60, owned_gameweeks: 15 },
      { player_id: 8, owned_points: 140, contributed_points: 160, owned_gameweeks: 30 },
      { player_id: 9, owned_points: 130, contributed_points: 120, owned_gameweeks: 28 },
      { player_id: 10, owned_points: 115, contributed_points: 110, owned_gameweeks: 24 },
      { player_id: 11, owned_points: 102, contributed_points: 98, owned_gameweeks: 22 },
      { player_id: 12, owned_points: 88, contributed_points: 80, owned_gameweeks: 18 },
      { player_id: 13, owned_points: 118, contributed_points: 112, owned_gameweeks: 26 },
      { player_id: 14, owned_points: 100, contributed_points: 96, owned_gameweeks: 21 },
      { player_id: 15, owned_points: 83, contributed_points: 77, owned_gameweeks: 17 },
    ]

    const result = buildSeasonBestSquad(seasonPlayerPoints, players)

    expect(result).not.toBeNull()
    expect(result!.picks).toHaveLength(15)
    expect(result!.picks.filter((p) => p.position <= 11)).toHaveLength(11)
    expect(result!.picks.filter((p) => p.position > 11)).toHaveLength(4)

    const captain = result!.picks.find((p) => p.is_captain)
    expect(captain?.element).toBe(8)

    const bench = result!.picks.filter((p) => p.position > 11)
    const benchGoalkeepers = bench.filter((p) => players.get(p.element)?.element_type === 1)
    expect(benchGoalkeepers).toHaveLength(1)

    expect(result!.playerPoints[8]).toBe(140)
    expect(result!.playerPoints[3]).toBe(110)
  })

  it('returns null when there are not enough players by position', () => {
    const players = new Map<number, Player>([
      [1, createPlayer(1, 1)], // Only one GK, should fail
      [3, createPlayer(3, 2)],
      [4, createPlayer(4, 2)],
      [5, createPlayer(5, 2)],
      [6, createPlayer(6, 2)],
      [7, createPlayer(7, 2)],
      [8, createPlayer(8, 3)],
      [9, createPlayer(9, 3)],
      [10, createPlayer(10, 3)],
      [11, createPlayer(11, 3)],
      [12, createPlayer(12, 3)],
      [13, createPlayer(13, 4)],
      [14, createPlayer(14, 4)],
      [15, createPlayer(15, 4)],
    ])

    const seasonPlayerPoints: SeasonPlayerPoints[] = Array.from(players.keys()).map((id) => ({
      player_id: id,
      owned_points: 50,
      contributed_points: 40,
      owned_gameweeks: 10,
    }))

    const result = buildSeasonBestSquad(seasonPlayerPoints, players)
    expect(result).toBeNull()
  })
})
