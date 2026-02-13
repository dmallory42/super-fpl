import type { GameweekFixtureStatus } from '../api/client'

type FixtureRow = GameweekFixtureStatus['fixtures'][number]

export type TeamFixtureStatus = 'upcoming' | 'playing' | 'finished' | 'unknown'

export function toBoolFlag(value: boolean | number | null | undefined): boolean {
  return value === true || value === 1
}

function hasLiveClock(fixture: FixtureRow): boolean {
  return toBoolFlag(fixture.started) && !toBoolFlag(fixture.finished) && (fixture.minutes ?? 0) > 0
}

export function getFixturesForTeam(
  fixtureData: GameweekFixtureStatus | undefined,
  teamId: number
): FixtureRow[] {
  if (!fixtureData) return []

  return fixtureData.fixtures
    .filter((f) => f.home_club_id === teamId || f.away_club_id === teamId)
    .sort((a, b) => {
      const kickoffDiff = new Date(a.kickoff_time).getTime() - new Date(b.kickoff_time).getTime()
      if (kickoffDiff !== 0) return kickoffDiff
      return a.id - b.id
    })
}

export function getTeamFixtureStatus(
  fixtureData: GameweekFixtureStatus | undefined,
  teamId: number | undefined
): TeamFixtureStatus {
  if (!teamId || !fixtureData) return 'unknown'

  const fixtures = getFixturesForTeam(fixtureData, teamId)
  if (fixtures.length === 0) return 'unknown'

  if (fixtures.some((f) => hasLiveClock(f))) return 'playing'
  if (fixtures.every((f) => toBoolFlag(f.finished))) return 'finished'
  return 'upcoming'
}

export function resolveFixtureForTeam(
  fixtureData: GameweekFixtureStatus | undefined,
  teamId: number,
  explainFixtureIds: number[] = []
): FixtureRow | undefined {
  const fixtures = getFixturesForTeam(fixtureData, teamId)
  if (fixtures.length === 0) return undefined

  const eventCandidates = fixtures.filter((f) => toBoolFlag(f.finished) || hasLiveClock(f))
  const candidates = eventCandidates.length > 0 ? eventCandidates : fixtures

  if (explainFixtureIds.length > 0) {
    const explainMatch = candidates.filter((f) => explainFixtureIds.includes(f.id))
    if (explainMatch.length > 0) {
      return explainMatch.sort((a, b) => {
        if (hasLiveClock(a) !== hasLiveClock(b)) return hasLiveClock(a) ? -1 : 1
        return (b.minutes ?? 0) - (a.minutes ?? 0) || b.id - a.id
      })[0]
    }
  }

  return candidates.sort((a, b) => {
    if (hasLiveClock(a) !== hasLiveClock(b)) return hasLiveClock(a) ? -1 : 1
    return (b.minutes ?? 0) - (a.minutes ?? 0) || b.id - a.id
  })[0]
}
