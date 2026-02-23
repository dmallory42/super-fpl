import type { PredictionBreakdown } from '../api/client'

const POSITION_GKP = 1
const POSITION_DEF = 2
const POSITION_MID = 3

/**
 * Unified prediction scaling.
 *
 * Scales "if fit" predicted points by the ratio of effective minutes
 * to if-fit expected minutes. Works for all players including injured.
 */
export function scalePoints(
  ifFitPts: number,
  ifFitMins: number,
  effectiveMins: number,
  ifFitBreakdown?: PredictionBreakdown,
  fixtureCount: number = 1,
  position?: number
): number {
  if (ifFitMins <= 0) return 0
  const ratio = effectiveMins / ifFitMins
  if (ratio <= 0) return 0
  if (
    !ifFitBreakdown ||
    typeof ifFitBreakdown.appearance !== 'number' ||
    typeof ifFitBreakdown.goals !== 'number' ||
    typeof ifFitBreakdown.assists !== 'number' ||
    typeof ifFitBreakdown.clean_sheet !== 'number' ||
    typeof ifFitBreakdown.bonus !== 'number' ||
    typeof ifFitBreakdown.goals_conceded !== 'number' ||
    typeof ifFitBreakdown.saves !== 'number' ||
    typeof ifFitBreakdown.defensive_contribution !== 'number' ||
    typeof ifFitBreakdown.cards !== 'number'
  ) {
    return ifFitPts * ratio
  }

  const boundedAppearanceCap = 2 * Math.max(1, fixtureCount)
  const boundedBonusCap = 3 * Math.max(1, fixtureCount)
  const boundedCsCapPerFixture =
    position === POSITION_GKP || position === POSITION_DEF ? 4 : position === POSITION_MID ? 1 : 0
  const boundedCsCap = boundedCsCapPerFixture * Math.max(1, fixtureCount)
  const boundedDcCap =
    position != null && position >= POSITION_DEF ? 2 * Math.max(1, fixtureCount) : 0

  const scaledAppearance = Math.min(
    boundedAppearanceCap,
    Math.max(0, ifFitBreakdown.appearance * ratio)
  )
  const scaledBonus = Math.min(boundedBonusCap, Math.max(0, ifFitBreakdown.bonus * ratio))
  const scaledCleanSheet =
    boundedCsCapPerFixture > 0
      ? Math.min(boundedCsCap, Math.max(0, ifFitBreakdown.clean_sheet * ratio))
      : ifFitBreakdown.clean_sheet * ratio
  const scaledDefensiveContribution =
    boundedDcCap > 0
      ? Math.min(boundedDcCap, Math.max(0, ifFitBreakdown.defensive_contribution * ratio))
      : ifFitBreakdown.defensive_contribution * ratio

  return (
    scaledAppearance +
    ifFitBreakdown.goals * ratio +
    ifFitBreakdown.assists * ratio +
    scaledCleanSheet +
    scaledBonus +
    ifFitBreakdown.goals_conceded * ratio +
    ifFitBreakdown.saves * ratio +
    scaledDefensiveContribution +
    ifFitBreakdown.cards * ratio +
    (ifFitBreakdown.penalties ?? 0) * ratio
  )
}
