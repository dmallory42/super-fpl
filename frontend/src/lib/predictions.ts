/**
 * Unified prediction scaling.
 *
 * Scales "if fit" predicted points by the ratio of effective minutes
 * to if-fit expected minutes. Works for all players including injured.
 */
export function scalePoints(ifFitPts: number, ifFitMins: number, effectiveMins: number): number {
  if (ifFitMins <= 0) return 0
  return ifFitPts * (effectiveMins / ifFitMins)
}
