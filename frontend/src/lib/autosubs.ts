/**
 * Apply FPL auto-sub rules to a squad.
 *
 * A player is subbed out if their match is finished AND they played 0 minutes.
 * Subs happen in bench order (positions 12-15).
 * Formation constraints: exactly 1 GK, min 3 DEF, min 2 MID, min 1 FWD,
 * max 5 DEF, max 5 MID, max 3 FWD.
 * GK can only replace GK and vice versa.
 */

import type { LiveManagerPlayer, GameweekFixtureStatus } from '../api/client'

export type PlayerInfo = { web_name: string; team: number; element_type: number }

export interface AutoSubResult {
  players: LiveManagerPlayer[]
  totalPoints: number
  benchPoints: number
  autoSubs: Array<{ out: number; in: number }>
}

export function applyAutoSubs(
  players: LiveManagerPlayer[],
  playersInfo: Map<number, PlayerInfo>,
  fixtureData: GameweekFixtureStatus | undefined
): AutoSubResult {
  if (!fixtureData) {
    // Can't determine match status, return original
    const starting = players.filter((p) => p.position <= 11)
    const bench = players.filter((p) => p.position > 11)
    return {
      players,
      totalPoints: starting.reduce((sum, p) => sum + p.effective_points, 0),
      benchPoints: bench.reduce((sum, p) => sum + p.points, 0),
      autoSubs: [],
    }
  }

  // Helper to check if a player's match is finished
  const isMatchFinished = (playerId: number): boolean => {
    const info = playersInfo.get(playerId)
    if (!info) return false
    const teamId = info.team
    const fixture = fixtureData.fixtures.find(
      (f) => f.home_club_id === teamId || f.away_club_id === teamId
    )
    return fixture?.finished ?? false
  }

  // Helper to check if player didn't play (0 minutes and match finished)
  const didNotPlay = (player: LiveManagerPlayer): boolean => {
    const minutes = player.stats?.minutes ?? 0
    return minutes === 0 && isMatchFinished(player.player_id)
  }

  // Clone players array
  const result = players.map((p) => ({ ...p }))

  // Separate starting XI and bench
  const starting = result.filter((p) => p.position <= 11)
  const bench = result.filter((p) => p.position > 11).sort((a, b) => a.position - b.position)

  // Track auto-subs made
  const autoSubs: Array<{ out: number; in: number }> = []

  // Check if adding a player maintains valid formation
  const canSubIn = (subPlayer: LiveManagerPlayer, outPlayer: LiveManagerPlayer): boolean => {
    const subInfo = playersInfo.get(subPlayer.player_id)
    const outInfo = playersInfo.get(outPlayer.player_id)
    if (!subInfo || !outInfo) return false

    // GK can only replace GK
    if (outInfo.element_type === 1) {
      return subInfo.element_type === 1
    }
    // GK can only come on for GK
    if (subInfo.element_type === 1) {
      return outInfo.element_type === 1
    }

    // Count formation excluding the player going out
    const activeStarting = starting.filter(
      (p) => !didNotPlay(p) && p.player_id !== outPlayer.player_id
    )
    const counts = { gk: 0, def: 0, mid: 0, fwd: 0 }
    for (const p of activeStarting) {
      const info = playersInfo.get(p.player_id)
      if (!info) continue
      switch (info.element_type) {
        case 1:
          counts.gk++
          break
        case 2:
          counts.def++
          break
        case 3:
          counts.mid++
          break
        case 4:
          counts.fwd++
          break
      }
    }

    // Add the incoming player
    switch (subInfo.element_type) {
      case 2:
        counts.def++
        break
      case 3:
        counts.mid++
        break
      case 4:
        counts.fwd++
        break
    }

    // Check constraints: min 3 DEF, min 2 MID, min 1 FWD
    // Also max 5 DEF, max 5 MID, max 3 FWD (11 - 1 GK = 10 outfield)
    return (
      counts.def >= 3 &&
      counts.mid >= 2 &&
      counts.fwd >= 1 &&
      counts.def <= 5 &&
      counts.mid <= 5 &&
      counts.fwd <= 3
    )
  }

  // Process auto-subs
  for (const startingPlayer of starting) {
    if (!didNotPlay(startingPlayer)) continue

    // Find first eligible bench player
    for (const benchPlayer of bench) {
      // Skip if bench player already used or didn't play
      if (benchPlayer.position <= 11) continue // Already subbed in
      if (didNotPlay(benchPlayer)) continue
      if (!isMatchFinished(benchPlayer.player_id)) continue // Match not finished yet

      if (canSubIn(benchPlayer, startingPlayer)) {
        // Perform the sub: swap positions
        const oldStartingPos = startingPlayer.position
        startingPlayer.position = benchPlayer.position
        benchPlayer.position = oldStartingPos

        // Bench players have multiplier 0 in FPL API, set to 1 when subbed in
        benchPlayer.multiplier = 1
        benchPlayer.effective_points = benchPlayer.points

        // The player being subbed out gets 0 effective points and multiplier 0
        startingPlayer.multiplier = 0
        startingPlayer.effective_points = 0

        autoSubs.push({ out: startingPlayer.player_id, in: benchPlayer.player_id })
        break
      }
    }
  }

  // Recalculate points after subs
  const finalStarting = result.filter((p) => p.position <= 11)
  const finalBench = result.filter((p) => p.position > 11)

  const totalPoints = finalStarting.reduce((sum, p) => sum + p.effective_points, 0)
  const benchPoints = finalBench.reduce((sum, p) => sum + p.points, 0)

  return { players: result, totalPoints, benchPoints, autoSubs }
}
