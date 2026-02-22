import type { GameweekFixtureStatus, LiveManagerPlayer } from '../api/client'
import { getTeamFixtureStatus } from './fixtureMapping'

type PlayerInfo = { web_name: string; team: number; element_type: number }

export interface EffectivePlayersCount {
  played: number
  total: number
}

const MAX_MULTIPLIER = 3
const MAX_EFFECTIVE_OWNERSHIP = MAX_MULTIPLIER * 100

function clampMultiplier(value: number | null | undefined): number {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return 0
  }
  return Math.max(0, Math.min(MAX_MULTIPLIER, value))
}

function hasPlayed(status: ReturnType<typeof getTeamFixtureStatus>): boolean {
  return status === 'playing' || status === 'finished'
}

export function calculateUserEffectivePlayersPlayed(
  players: LiveManagerPlayer[],
  playersMap: Map<number, PlayerInfo>,
  fixtureData: GameweekFixtureStatus | undefined
): EffectivePlayersCount {
  let played = 0
  let total = 0

  for (const player of players) {
    const weight = clampMultiplier(player.multiplier)
    if (weight <= 0) continue

    const info = playersMap.get(player.player_id)
    if (!info) continue

    total += weight
    if (fixtureData && hasPlayed(getTeamFixtureStatus(fixtureData, info.team))) {
      played += weight
    }
  }

  return { played, total }
}

export function calculateTierEffectivePlayersPlayed(
  effectiveOwnership: Record<number, number> | undefined,
  playersMap: Map<number, PlayerInfo>,
  fixtureData: GameweekFixtureStatus | undefined
): EffectivePlayersCount {
  if (!effectiveOwnership) {
    return { played: 0, total: 0 }
  }

  let played = 0
  let total = 0

  for (const [playerIdStr, eoRaw] of Object.entries(effectiveOwnership)) {
    const playerId = parseInt(playerIdStr, 10)
    const info = playersMap.get(playerId)
    if (!info) continue

    const eo = Math.max(0, Math.min(MAX_EFFECTIVE_OWNERSHIP, eoRaw))
    if (eo <= 0) continue

    const weight = eo / 100
    total += weight
    if (fixtureData && hasPlayed(getTeamFixtureStatus(fixtureData, info.team))) {
      played += weight
    }
  }

  return { played, total }
}
