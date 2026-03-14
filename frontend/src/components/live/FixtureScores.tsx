import { useState, useMemo } from 'react'
import type { GameweekFixtureStatus, LiveElement, BonusPrediction } from '../../api/client'
import { resolveFixtureForTeam, toBoolFlag } from '../../lib/fixtureMapping'

interface FixtureScoresProps {
  fixtureData: GameweekFixtureStatus | undefined
  teamsMap: Map<number, string>
  liveElements: LiveElement[] | undefined
  playersMap: Map<number, { web_name: string; team: number; element_type: number }>
  bonusPredictions: BonusPrediction[] | undefined
}

interface PlayerEvent {
  playerId: number
  name: string
  goals: number
  assists: number
  yellowCards: number
  redCards: number
  cleanSheet: boolean
  saves: number
  defCon: boolean
  bonus: number
  bps: number
  position: number
}

// Medal colors by bonus points value (3 = gold, 2 = silver, 1 = bronze)
const bonusMedalStyles: Record<number, string> = {
  3: 'bg-amber-400 text-amber-950', // Gold
  2: 'bg-slate-300 text-slate-800', // Silver
  1: 'bg-orange-400 text-orange-950', // Bronze
}

export function FixtureScores({
  fixtureData,
  teamsMap,
  liveElements,
  playersMap,
  bonusPredictions,
}: FixtureScoresProps) {
  const hasLiveClock = (fixture: GameweekFixtureStatus['fixtures'][number]) =>
    toBoolFlag(fixture.started) && !toBoolFlag(fixture.finished) && (fixture.minutes ?? 0) > 0
  const fixtureTeamKey = (fixtureId: number, teamId: number) => `${fixtureId}:${teamId}`

  const [expandedFixture, setExpandedFixture] = useState<number | null>(null)
  const localTimeZoneShort = useMemo(() => {
    const parts = new Intl.DateTimeFormat(undefined, {
      hour: '2-digit',
      minute: '2-digit',
      timeZoneName: 'short',
    }).formatToParts(new Date())
    return parts.find((part) => part.type === 'timeZoneName')?.value ?? 'local'
  }, [])
  const kickoffTimeFormatter = useMemo(
    () =>
      new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
      }),
    []
  )
  const formatKickoffTime = (kickoffTime: string) => {
    const date = new Date(kickoffTime)
    if (Number.isNaN(date.getTime())) return '--:--'
    const parts = kickoffTimeFormatter.formatToParts(date)
    const hour = parts.find((part) => part.type === 'hour')?.value
    const minute = parts.find((part) => part.type === 'minute')?.value
    if (hour && minute) return `${hour}:${minute}`
    return kickoffTimeFormatter.format(date)
  }

  const playerEventsByFixtureTeam = useMemo(() => {
    if (!liveElements) return new Map<string, PlayerEvent[]>()

    const byFixtureTeam = new Map<string, PlayerEvent[]>()

    for (const element of liveElements) {
      const info = playersMap.get(element.id)
      if (!info) continue

      const stats = element.stats
      if (!stats) continue

      const explainFixtureIds =
        element.explain
          ?.map((entry) => entry.fixture)
          .filter((fixtureId): fixtureId is number => typeof fixtureId === 'number') ?? []

      const resolvedFixture = resolveFixtureForTeam(fixtureData, info.team, explainFixtureIds)
      if (!resolvedFixture) continue
      const fixtureId = resolvedFixture.id

      const hasCleanSheet =
        stats.clean_sheets > 0 && (info.element_type === 1 || info.element_type === 2)
      const hasSaves = stats.saves >= 3 && info.element_type === 1
      const hasDefCon =
        (element.explain
          ?.find((entry) => entry.fixture === fixtureId)
          ?.stats.find((stat) => stat.identifier === 'defensive_contribution')?.points ?? 0) > 0
      const hasCards = stats.yellow_cards > 0 || stats.red_cards > 0

      if (
        stats.goals_scored === 0 &&
        stats.assists === 0 &&
        !hasCards &&
        !hasCleanSheet &&
        !hasSaves &&
        !hasDefCon &&
        stats.bonus === 0
      ) {
        continue
      }

      const key = fixtureTeamKey(fixtureId, info.team)
      const teamEvents = byFixtureTeam.get(key) || []
      teamEvents.push({
        playerId: element.id,
        name: info.web_name,
        goals: stats.goals_scored,
        assists: stats.assists,
        yellowCards: stats.yellow_cards,
        redCards: stats.red_cards,
        cleanSheet: hasCleanSheet,
        saves: stats.saves,
        defCon: hasDefCon,
        bonus: stats.bonus,
        bps: stats.bps,
        position: info.element_type,
      })
      byFixtureTeam.set(key, teamEvents)
    }

    for (const [, events] of byFixtureTeam) {
      events.sort((a, b) => {
        if (b.goals !== a.goals) return b.goals - a.goals
        if (b.assists !== a.assists) return b.assists - a.assists
        return b.bps - a.bps
      })
    }

    return byFixtureTeam
  }, [fixtureData, liveElements, playersMap])

  const getBonusForFixture = (fixtureId: number) => {
    if (!bonusPredictions) return []
    // Return all players with bonus points (handles ties), sorted by bonus then BPS
    return bonusPredictions
      .filter((bp) => bp.fixture_id === fixtureId && bp.predicted_bonus > 0)
      .sort((a, b) => b.predicted_bonus - a.predicted_bonus || b.bps - a.bps)
  }

  if (!fixtureData?.fixtures?.length) {
    return (
      <div className="text-center text-foreground-muted py-4 text-sm">No fixtures available</div>
    )
  }

  const groups = (() => {
    const live: typeof fixtureData.fixtures = []
    const finished: typeof fixtureData.fixtures = []
    const upcoming: typeof fixtureData.fixtures = []

    for (const f of fixtureData.fixtures) {
      if (toBoolFlag(f.finished)) finished.push(f)
      else if (hasLiveClock(f)) live.push(f)
      else upcoming.push(f)
    }

    const byKickoff = (a: (typeof live)[0], b: (typeof live)[0]) =>
      new Date(a.kickoff_time).getTime() - new Date(b.kickoff_time).getTime()
    live.sort(byKickoff)
    finished.sort(byKickoff)
    upcoming.sort(byKickoff)

    const result: Array<{ label: string; isLiveGroup: boolean; fixtures: typeof live }> = []
    if (live.length > 0) result.push({ label: 'Live', isLiveGroup: true, fixtures: live })
    if (finished.length > 0)
      result.push({ label: 'Finished', isLiveGroup: false, fixtures: finished })
    if (upcoming.length > 0)
      result.push({ label: 'Upcoming', isLiveGroup: false, fixtures: upcoming })
    return result
  })()

  return (
    <div className="space-y-3">
      <div className="text-xs text-foreground-dim px-1">
        Kickoff times shown in {localTimeZoneShort}
      </div>
      {groups.map((group) => (
        <div key={group.label} className="space-y-1.5">
          <div className="flex items-center gap-2 px-1">
            {group.isLiveGroup && (
              <span className="w-1.5 h-1.5 rounded-full bg-tt-green animate-blink" />
            )}
            <span className="text-xs uppercase tracking-wide text-foreground-dim">
              {group.label}
            </span>
          </div>
          {group.fixtures.map((fixture) => {
            const homeTeam = teamsMap.get(fixture.home_club_id) || '???'
            const awayTeam = teamsMap.get(fixture.away_club_id) || '???'
            const isLive = hasLiveClock(fixture)
            const isFinished = toBoolFlag(fixture.finished)
            const isUpcoming = !isFinished && !isLive
            const isExpanded = expandedFixture === fixture.id

            const homeEvents =
              playerEventsByFixtureTeam.get(fixtureTeamKey(fixture.id, fixture.home_club_id)) || []
            const awayEvents =
              playerEventsByFixtureTeam.get(fixtureTeamKey(fixture.id, fixture.away_club_id)) || []
            const projectedBonusPlayers = getBonusForFixture(fixture.id)
            const officialBonusPlayers = [...homeEvents, ...awayEvents]
              .filter((event) => event.bonus > 0)
              .map((event) => ({
                player_id: event.playerId,
                bps: event.bps,
                predicted_bonus: event.bonus,
                fixture_id: fixture.id,
              }))
              .sort((a, b) => b.predicted_bonus - a.predicted_bonus || b.bps - a.bps)

            const bonusPlayers = isFinished ? officialBonusPlayers : projectedBonusPlayers

            const hasDetails =
              homeEvents.length > 0 || awayEvents.length > 0 || bonusPlayers.length > 0

            return (
              <div key={fixture.id}>
                {/* Main fixture row */}
                <button
                  onClick={() => hasDetails && setExpandedFixture(isExpanded ? null : fixture.id)}
                  disabled={!hasDetails}
                  className={`
                w-full flex items-center justify-between p-2 text-xs
                ${
                  isLive
                    ? 'bg-tt-green/10 ring-1 ring-tt-green/40'
                    : 'bg-surface-elevated hover:bg-surface-elevated/80'
                }
                ${hasDetails ? 'cursor-pointer' : 'cursor-default'}
              `}
                >
                  {/* Home team */}
                  <div className="flex-1 text-right pr-2">
                    <span
                      className={`text-sm uppercase tracking-wide ${isFinished ? 'text-foreground-muted' : 'text-foreground'}`}
                    >
                      {homeTeam}
                    </span>
                  </div>

                  {/* Score block */}
                  <div
                    className={`
                flex items-center gap-1 px-3 py-1 min-w-[90px] justify-center
                ${isLive ? 'bg-tt-green/10' : isFinished ? 'bg-surface/50' : ''}
              `}
                  >
                    {isUpcoming ? (
                      <span className="text-sm text-foreground-dim">
                        {formatKickoffTime(fixture.kickoff_time)}
                      </span>
                    ) : (
                      <>
                        <span
                          className={`text-base font-bold ${isLive ? 'text-tt-green' : 'text-foreground'}`}
                        >
                          {fixture.home_score ?? 0}
                        </span>
                        <span
                          className={`text-xs ${isLive ? 'text-tt-green/60' : 'text-foreground-dim'}`}
                        >
                          –
                        </span>
                        <span
                          className={`text-base font-bold ${isLive ? 'text-tt-green' : 'text-foreground'}`}
                        >
                          {fixture.away_score ?? 0}
                        </span>
                      </>
                    )}
                    {isLive ? (
                      <span className="text-xs text-tt-green ml-1 animate-blink font-medium">
                        {fixture.minutes}'
                      </span>
                    ) : null}
                    {isFinished ? (
                      <span className="text-xs text-foreground-dim uppercase ml-1 tracking-wide">
                        FT
                      </span>
                    ) : null}
                  </div>

                  {/* Away team */}
                  <div className="flex-1 text-left pl-2 flex items-center gap-1.5">
                    <span
                      className={`text-sm uppercase tracking-wide ${isFinished ? 'text-foreground-muted' : 'text-foreground'}`}
                    >
                      {awayTeam}
                    </span>
                    {hasDetails && (
                      <span
                        className={`text-xs text-foreground-dim/60 ${isExpanded ? 'rotate-180' : ''}`}
                      >
                        ▼
                      </span>
                    )}
                  </div>
                </button>

                {/* Expanded details */}
                {isExpanded && hasDetails && (
                  <div className="mt-1 mx-1 p-3 bg-surface text-xs space-y-3 border border-border/20">
                    {/* Scorers and events */}
                    {(homeEvents.length > 0 || awayEvents.length > 0) && (
                      <div className="grid grid-cols-2 gap-4">
                        {/* Home events */}
                        <div className="space-y-1">
                          {homeEvents.map((event) => (
                            <div
                              key={event.playerId}
                              className="flex items-center gap-1.5 text-foreground"
                            >
                              <span className="truncate">{event.name}</span>
                              <span className="flex items-center gap-0.5 shrink-0">
                                {event.goals > 0 && (
                                  <span className="text-[11px]">{'⚽'.repeat(event.goals)}</span>
                                )}
                                {event.assists > 0 && (
                                  <span className="text-[11px]">{'🅰️'.repeat(event.assists)}</span>
                                )}
                                {event.yellowCards > 0 && (
                                  <span
                                    className="text-[11px]"
                                    title={`${event.yellowCards} yellow card${event.yellowCards > 1 ? 's' : ''}`}
                                  >
                                    {'🟨'.repeat(event.yellowCards)}
                                  </span>
                                )}
                                {event.redCards > 0 && (
                                  <span
                                    className="text-[11px]"
                                    title={`${event.redCards} red card${event.redCards > 1 ? 's' : ''}`}
                                  >
                                    {'🟥'.repeat(event.redCards)}
                                  </span>
                                )}
                                {event.cleanSheet && (
                                  <span className="text-[11px]" title="Clean sheet">
                                    🛡️
                                  </span>
                                )}
                                {event.position === 1 && event.saves >= 3 && (
                                  <span className="text-[11px]" title={`${event.saves} saves`}>
                                    🧤
                                  </span>
                                )}
                                {event.defCon && (
                                  <span className="text-[11px]" title="Defensive contribution">
                                    💪
                                  </span>
                                )}
                                {isLive && (
                                  <span className="text-[10px] text-foreground-dim ml-1">
                                    BPS {event.bps}
                                  </span>
                                )}
                              </span>
                            </div>
                          ))}
                        </div>

                        {/* Away events */}
                        <div className="space-y-1">
                          {awayEvents.map((event) => (
                            <div
                              key={event.playerId}
                              className="flex items-center gap-1.5 text-foreground"
                            >
                              <span className="truncate">{event.name}</span>
                              <span className="flex items-center gap-0.5 shrink-0">
                                {event.goals > 0 && (
                                  <span className="text-[11px]">{'⚽'.repeat(event.goals)}</span>
                                )}
                                {event.assists > 0 && (
                                  <span className="text-[11px]">{'🅰️'.repeat(event.assists)}</span>
                                )}
                                {event.yellowCards > 0 && (
                                  <span
                                    className="text-[11px]"
                                    title={`${event.yellowCards} yellow card${event.yellowCards > 1 ? 's' : ''}`}
                                  >
                                    {'🟨'.repeat(event.yellowCards)}
                                  </span>
                                )}
                                {event.redCards > 0 && (
                                  <span
                                    className="text-[11px]"
                                    title={`${event.redCards} red card${event.redCards > 1 ? 's' : ''}`}
                                  >
                                    {'🟥'.repeat(event.redCards)}
                                  </span>
                                )}
                                {event.cleanSheet && (
                                  <span className="text-[11px]" title="Clean sheet">
                                    🛡️
                                  </span>
                                )}
                                {event.position === 1 && event.saves >= 3 && (
                                  <span className="text-[11px]" title={`${event.saves} saves`}>
                                    🧤
                                  </span>
                                )}
                                {event.defCon && (
                                  <span className="text-[11px]" title="Defensive contribution">
                                    💪
                                  </span>
                                )}
                                {isLive && (
                                  <span className="text-[10px] text-foreground-dim ml-1">
                                    BPS {event.bps}
                                  </span>
                                )}
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Bonus predictions with medal badges */}
                    {bonusPlayers.length > 0 && (
                      <div className="pt-2 border-t border-border/30">
                        <div className="text-xs text-foreground-dim uppercase tracking-wide mb-2">
                          Bonus Points
                        </div>
                        <div className="flex flex-wrap gap-3">
                          {bonusPlayers.map((bp) => {
                            const info = playersMap.get(bp.player_id)
                            return (
                              <div key={bp.player_id} className="flex items-center gap-1.5">
                                <span
                                  className={`
                              inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold
                              ${bonusMedalStyles[bp.predicted_bonus] || 'bg-foreground/10 text-foreground-muted'}
                            `}
                                >
                                  {bp.predicted_bonus}
                                </span>
                                <span className="text-foreground">{info?.web_name || '?'}</span>
                                <span className="text-foreground-dim text-xs">({bp.bps})</span>
                              </div>
                            )
                          })}
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      ))}
    </div>
  )
}
