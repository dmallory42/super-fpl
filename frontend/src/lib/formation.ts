/**
 * Build a valid FPL formation from a squad of players.
 *
 * Selects a starting XI that respects formation constraints
 * (1 GK, 3-5 DEF, 2-5 MID, 1-3 FWD), picks the captain
 * as the highest-predicted starter, and orders bench with
 * GK first then by predicted points.
 */

export interface SquadPlayer {
  player_id: number
  web_name: string
  element_type: number
  team: number
  predicted_points: number
  fixture?: string
}

export interface FormationPlayer extends SquadPlayer {
  multiplier: number
  position: number
  is_captain: boolean
}

const MIN_BY_POSITION: Record<number, number> = {
  1: 1, // GK
  2: 3, // DEF
  3: 0, // MID (no hard minimum beyond formation fill)
  4: 1, // FWD
}

const MAX_BY_POSITION: Record<number, number> = {
  1: 1, // GK
  2: 5, // DEF
  3: 5, // MID
  4: 3, // FWD
}

export function buildFormation(squad: SquadPlayer[]): FormationPlayer[] {
  // Group by position and sort by predicted points desc
  const byPosition: Record<number, SquadPlayer[]> = { 1: [], 2: [], 3: [], 4: [] }
  for (const p of squad) {
    byPosition[p.element_type]?.push(p)
  }
  for (const arr of Object.values(byPosition)) {
    arr.sort((a, b) => b.predicted_points - a.predicted_points)
  }

  // Start with max allowed per position
  const starting: SquadPlayer[] = [
    ...byPosition[1].slice(0, MAX_BY_POSITION[1]),
    ...byPosition[2].slice(0, MAX_BY_POSITION[2]),
    ...byPosition[3].slice(0, MAX_BY_POSITION[3]),
    ...byPosition[4].slice(0, MAX_BY_POSITION[4]),
  ]

  // Trim to 11 by removing the lowest scorers that aren't required
  if (starting.length > 11) {
    starting.sort((a, b) => b.predicted_points - a.predicted_points)
    while (starting.length > 11) {
      for (let i = starting.length - 1; i >= 0; i--) {
        const p = starting[i]
        const samePos = starting.filter((s) => s.element_type === p.element_type).length
        if (samePos > (MIN_BY_POSITION[p.element_type] ?? 0)) {
          starting.splice(i, 1)
          break
        }
      }
    }
  }

  // Find captain (highest predicted in starting XI)
  const captainId = starting.reduce(
    (best, p) =>
      p.predicted_points > best.pts ? { id: p.player_id, pts: p.predicted_points } : best,
    { id: 0, pts: -1 }
  ).id

  // Separate starters and bench
  const startingIds = new Set(starting.map((p) => p.player_id))
  const starters = squad.filter((p) => startingIds.has(p.player_id))
  const bench = squad
    .filter((p) => !startingIds.has(p.player_id))
    .sort((a, b) => {
      // GK always first on bench
      if (a.element_type === 1 && b.element_type !== 1) return -1
      if (a.element_type !== 1 && b.element_type === 1) return 1
      return b.predicted_points - a.predicted_points
    })

  // Assign positions: starters 1-11 sorted by element_type, bench 12+
  starters.sort((a, b) => a.element_type - b.element_type)

  let pos = 1
  return [
    ...starters.map((p) => ({
      ...p,
      position: pos++,
      is_captain: p.player_id === captainId,
      multiplier: p.player_id === captainId ? 2 : 1,
    })),
    ...bench.map((p) => ({
      ...p,
      position: pos++,
      is_captain: false,
      multiplier: 1,
    })),
  ]
}
