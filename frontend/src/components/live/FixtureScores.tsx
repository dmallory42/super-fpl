import { useState, useMemo } from 'react'
import type { GameweekFixtureStatus, LiveElement, BonusPrediction } from '../../api/client'

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
  cleanSheet: boolean
  saves: number
  defCon: boolean
  bonus: number
  bps: number
  position: number
}

// Medal colors by bonus points value (3 = gold, 2 = silver, 1 = bronze)
const bonusMedalStyles: Record<number, string> = {
  3: 'bg-gradient-to-br from-amber-400 to-amber-600 text-amber-950 shadow-amber-500/30', // Gold
  2: 'bg-gradient-to-br from-slate-300 to-slate-400 text-slate-800 shadow-slate-400/30', // Silver
  1: 'bg-gradient-to-br from-orange-400 to-orange-600 text-orange-950 shadow-orange-500/30', // Bronze
}

export function FixtureScores({
  fixtureData,
  teamsMap,
  liveElements,
  playersMap,
  bonusPredictions,
}: FixtureScoresProps) {
  const [expandedFixture, setExpandedFixture] = useState<number | null>(null)
  const localTimeZoneShort = useMemo(() => {
    const parts = new Intl.DateTimeFormat(undefined, {
      hour: '2-digit',
      minute: '2-digit',
      timeZoneName: 'short',
    }).formatToParts(new Date())
    return parts.find((part) => part.type === 'timeZoneName')?.value ?? 'local'
  }, [])

  const playerEventsByTeam = useMemo(() => {
    if (!liveElements) return new Map<number, PlayerEvent[]>()

    const byTeam = new Map<number, PlayerEvent[]>()

    for (const element of liveElements) {
      const info = playersMap.get(element.id)
      if (!info) continue

      const stats = element.stats
      if (!stats) continue

      const hasCleanSheet =
        stats.clean_sheets > 0 && (info.element_type === 1 || info.element_type === 2)
      const hasSaves = stats.saves >= 3 && info.element_type === 1

      const hasDefCon =
        element.explain?.some((e) =>
          e.stats.some((s) => s.identifier === 'defensive_contribution' && s.points > 0)
        ) ?? false

      if (
        stats.goals_scored === 0 &&
        stats.assists === 0 &&
        !hasCleanSheet &&
        !hasSaves &&
        !hasDefCon &&
        stats.bonus === 0
      )
        continue

      const bonus = bonusPredictions?.find((bp) => bp.player_id === element.id)

      const event: PlayerEvent = {
        playerId: element.id,
        name: info.web_name,
        goals: stats.goals_scored,
        assists: stats.assists,
        cleanSheet: hasCleanSheet,
        saves: stats.saves,
        defCon: hasDefCon,
        bonus: stats.bonus || bonus?.predicted_bonus || 0,
        bps: stats.bps,
        position: info.element_type,
      }

      const teamEvents = byTeam.get(info.team) || []
      teamEvents.push(event)
      byTeam.set(info.team, teamEvents)
    }

    for (const [, events] of byTeam) {
      events.sort((a, b) => {
        if (b.goals !== a.goals) return b.goals - a.goals
        if (b.assists !== a.assists) return b.assists - a.assists
        return b.bps - a.bps
      })
    }

    return byTeam
  }, [liveElements, playersMap, bonusPredictions])

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
      if (f.started && !f.finished) live.push(f)
      else if (f.finished) finished.push(f)
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

  let fixtureIdx = 0

  return (
    <div className="space-y-3">
      <div className="text-xs text-foreground-dim px-1">
        Kickoff times shown in {localTimeZoneShort}
      </div>
      {groups.map((group) => (
        <div key={group.label} className="space-y-1.5">
          <div className="flex items-center gap-2 px-1">
            {group.isLiveGroup && (
              <span className="w-1.5 h-1.5 rounded-full bg-fpl-green animate-pulse" />
            )}
            <span className="text-xs font-display uppercase tracking-wide text-foreground-dim">
              {group.label}
            </span>
          </div>
          {group.fixtures.map((fixture) => {
            const idx = fixtureIdx++
            const homeTeam = teamsMap.get(fixture.home_club_id) || '???'
            const awayTeam = teamsMap.get(fixture.away_club_id) || '???'
            const isLive = fixture.started && !fixture.finished
            const isFinished = fixture.finished
            const isUpcoming = !fixture.started
            const isExpanded = expandedFixture === fixture.id

            const homeEvents = playerEventsByTeam.get(fixture.home_club_id) || []
            const awayEvents = playerEventsByTeam.get(fixture.away_club_id) || []
            const bonusPlayers = getBonusForFixture(fixture.id)

            const hasDetails =
              homeEvents.length > 0 || awayEvents.length > 0 || bonusPlayers.length > 0

            return (
              <div key={fixture.id}>
                {/* Main fixture row */}
                <button
                  onClick={() => hasDetails && setExpandedFixture(isExpanded ? null : fixture.id)}
                  disabled={!hasDetails}
                  className={`
                w-full flex items-center justify-between p-2 rounded-lg text-xs transition-all duration-200
                animate-fade-in-up-fast opacity-0
                ${
                  isLive
                    ? 'bg-gradient-to-r from-fpl-green/5 via-fpl-green/10 to-fpl-green/5 ring-1 ring-fpl-green/40'
                    : 'bg-surface-elevated hover:bg-surface-elevated/80'
                }
                ${hasDetails ? 'cursor-pointer' : 'cursor-default'}
              `}
                  style={{ animationDelay: `${idx * 30}ms` }}
                >
                  {/* Home team */}
                  <div className="flex-1 text-right pr-2">
                    <span
                      className={`font-display text-sm uppercase tracking-wide ${isFinished ? 'text-foreground-muted' : 'text-foreground'}`}
                    >
                      {homeTeam}
                    </span>
                  </div>

                  {/* Score block */}
                  <div
                    className={`
                flex items-center gap-1 px-3 py-1 min-w-[90px] justify-center rounded
                ${isLive ? 'bg-fpl-green/10' : isFinished ? 'bg-surface/50' : ''}
              `}
                  >
                    {isUpcoming ? (
                      <span className="font-mono text-sm text-foreground-dim">
                        {new Date(fixture.kickoff_time).toLocaleTimeString(undefined, {
                          hour: '2-digit',
                          minute: '2-digit',
                          hour12: false,
                        })}
                      </span>
                    ) : (
                      <>
                        <span
                          className={`font-mono text-base font-bold ${isLive ? 'text-fpl-green' : 'text-foreground'}`}
                        >
                          {fixture.home_score ?? 0}
                        </span>
                        <span
                          className={`text-xs ${isLive ? 'text-fpl-green/60' : 'text-foreground-dim'}`}
                        >
                          –
                        </span>
                        <span
                          className={`font-mono text-base font-bold ${isLive ? 'text-fpl-green' : 'text-foreground'}`}
                        >
                          {fixture.away_score ?? 0}
                        </span>
                      </>
                    )}
                    {isLive && (
                      <span className="text-xs text-fpl-green font-mono ml-1 animate-pulse font-medium">
                        {fixture.minutes}'
                      </span>
                    )}
                    {isFinished && (
                      <span className="text-xs text-foreground-dim font-display uppercase ml-1 tracking-wide">
                        FT
                      </span>
                    )}
                  </div>

                  {/* Away team */}
                  <div className="flex-1 text-left pl-2 flex items-center gap-1.5">
                    <span
                      className={`font-display text-sm uppercase tracking-wide ${isFinished ? 'text-foreground-muted' : 'text-foreground'}`}
                    >
                      {awayTeam}
                    </span>
                    {hasDetails && (
                      <span
                        className={`text-xs text-foreground-dim/60 transition-transform duration-200 ${isExpanded ? 'rotate-180' : ''}`}
                      >
                        ▼
                      </span>
                    )}
                  </div>
                </button>

                {/* Expanded details */}
                {isExpanded && hasDetails && (
                  <div className="mt-1 mx-1 p-3 bg-gradient-to-b from-surface to-surface/50 rounded-lg text-xs space-y-3 animate-fade-in-up-fast border border-border/20">
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
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Bonus predictions with medal badges */}
                    {bonusPlayers.length > 0 && (
                      <div className="pt-2 border-t border-border/30">
                        <div className="text-xs text-foreground-dim font-display uppercase tracking-wide mb-2">
                          Bonus Points
                        </div>
                        <div className="flex flex-wrap gap-3">
                          {bonusPlayers.map((bp) => {
                            const info = playersMap.get(bp.player_id)
                            return (
                              <div key={bp.player_id} className="flex items-center gap-1.5">
                                <span
                                  className={`
                              inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-mono font-bold shadow-md
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
