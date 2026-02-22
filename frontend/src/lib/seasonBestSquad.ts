import type { SeasonPlayerPoints } from '../api/client'
import { buildFormation } from './formation'
import type { Pick, Player } from '../types'

const REQUIRED_BY_POSITION: Record<number, number> = {
  1: 2, // GK
  2: 5, // DEF
  3: 5, // MID
  4: 3, // FWD
}

interface SeasonBestCandidate {
  player: Player
  ownedPoints: number
  contributedPoints: number
}

export interface SeasonBestSquadResult {
  picks: Pick[]
  playerPoints: Record<number, number>
}

export function buildSeasonBestSquad(
  seasonPlayerPoints: SeasonPlayerPoints[] | undefined,
  playersMap: Map<number, Player>
): SeasonBestSquadResult | null {
  if (!seasonPlayerPoints || seasonPlayerPoints.length === 0) {
    return null
  }

  const byPosition: Record<number, SeasonBestCandidate[]> = {
    1: [],
    2: [],
    3: [],
    4: [],
  }

  for (const row of seasonPlayerPoints) {
    const player = playersMap.get(row.player_id)
    if (!player) continue
    if (!byPosition[player.element_type]) continue

    byPosition[player.element_type].push({
      player,
      ownedPoints: row.owned_points,
      contributedPoints: row.contributed_points,
    })
  }

  for (const position of Object.keys(byPosition).map(Number)) {
    byPosition[position].sort(
      (a, b) =>
        b.ownedPoints - a.ownedPoints ||
        b.contributedPoints - a.contributedPoints ||
        a.player.id - b.player.id
    )
  }

  const selected = Object.entries(REQUIRED_BY_POSITION)
    .map(([positionStr, required]) => {
      const position = Number(positionStr)
      return byPosition[position].slice(0, required)
    })
    .flat()

  if (selected.length !== 15) {
    return null
  }

  const formationPlayers = buildFormation(
    selected.map((candidate) => ({
      player_id: candidate.player.id,
      web_name: candidate.player.web_name,
      element_type: candidate.player.element_type,
      team: candidate.player.team,
      predicted_points: candidate.ownedPoints,
    }))
  )

  const playerPoints: Record<number, number> = {}
  for (const candidate of selected) {
    playerPoints[candidate.player.id] = candidate.ownedPoints
  }

  const picks: Pick[] = formationPlayers.map((player) => ({
    element: player.player_id,
    position: player.position,
    multiplier: player.position <= 11 ? (player.is_captain ? 2 : 1) : 0,
    is_captain: player.is_captain,
    is_vice_captain: false,
  }))

  return { picks, playerPoints }
}
