import { useMemo } from 'react'
import { PlayerStatusCard, type PlayerStatus } from './PlayerStatusCard'
import type { LiveManagerPlayer } from '../../api/client'
import type { GameweekFixtureStatus } from '../../api/client'

interface LiveFormationPitchProps {
  players: LiveManagerPlayer[]
  playersInfo: Map<number, { web_name: string; team: number; element_type: number }>
  teamsInfo: Map<number, string>
  fixtureData?: GameweekFixtureStatus
  effectiveOwnership?: Record<number, number>
}

export function LiveFormationPitch({
  players,
  playersInfo,
  teamsInfo,
  fixtureData,
  effectiveOwnership,
}: LiveFormationPitchProps) {
  const { starting, bench } = useMemo(() => {
    const sorted = [...players].sort((a, b) => a.position - b.position)
    return {
      starting: sorted.filter(p => p.position <= 11),
      bench: sorted.filter(p => p.position > 11),
    }
  }, [players])

  // Group starting players by position type (element_type)
  const rows = useMemo(() => {
    const gk: typeof starting = []
    const def: typeof starting = []
    const mid: typeof starting = []
    const fwd: typeof starting = []

    for (const player of starting) {
      const info = playersInfo.get(player.player_id)
      const elementType = info?.element_type ?? 3

      switch (elementType) {
        case 1: gk.push(player); break
        case 2: def.push(player); break
        case 3: mid.push(player); break
        case 4: fwd.push(player); break
      }
    }

    // Order: FWD at top (attacking end), then MID, DEF, GK at bottom
    return [fwd, mid, def, gk]
  }, [starting, playersInfo])

  // Get player's fixture status
  const getPlayerStatus = (playerId: number): { status: PlayerStatus; minutes?: number } => {
    const info = playersInfo.get(playerId)
    if (!info || !fixtureData) return { status: 'unknown' }

    const teamId = info.team
    const fixture = fixtureData.fixtures.find(
      f => f.home_club_id === teamId || f.away_club_id === teamId
    )

    if (!fixture) return { status: 'unknown' }
    if (fixture.finished) return { status: 'finished' }
    if (fixture.started) return { status: 'playing', minutes: fixture.minutes }
    return { status: 'upcoming' }
  }

  let animationIndex = 0

  return (
    <div className="pitch-texture rounded-lg p-4 relative overflow-hidden">
      {/* Pitch markings */}
      <div className="absolute inset-4 border-2 border-white/20 rounded pointer-events-none" />
      <div className="absolute left-1/2 top-4 bottom-4 w-px bg-white/10 pointer-events-none" />
      <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 border-2 border-white/10 rounded-full pointer-events-none" />

      {/* Goal area at bottom */}
      <div className="absolute bottom-4 left-1/2 -translate-x-1/2 w-32 h-12 border-t-2 border-x-2 border-white/10 rounded-t-lg pointer-events-none" />

      {/* Formation display */}
      <div className="relative z-10 flex flex-col gap-6 py-4">
        {rows.map((row, rowIndex) => (
          <div key={rowIndex} className="flex justify-center gap-2 md:gap-4">
            {row.map((player) => {
              const info = playersInfo.get(player.player_id)
              const teamName = info?.team ? teamsInfo.get(info.team) ?? '???' : '???'
              const { status, minutes } = getPlayerStatus(player.player_id)
              const delay = animationIndex++ * 50

              return (
                <PlayerStatusCard
                  key={player.player_id}
                  playerId={player.player_id}
                  webName={info?.web_name ?? `P${player.player_id}`}
                  teamName={teamName}
                  teamId={info?.team ?? 0}
                  points={player.points}
                  multiplier={player.multiplier}
                  isCaptain={player.is_captain}
                  isViceCaptain={false} // TODO: Add vice captain to response if needed
                  status={status}
                  matchMinute={minutes}
                  effectiveOwnership={effectiveOwnership?.[player.player_id]}
                  animationDelay={delay}
                />
              )
            })}
          </div>
        ))}
      </div>

      {/* Bench */}
      <div className="mt-6 pt-4 border-t-2 border-white/20">
        <div className="flex items-center justify-center gap-2 mb-3">
          <div className="h-px flex-1 bg-gradient-to-r from-transparent to-white/20" />
          <span className="font-display text-xs uppercase tracking-wider text-white/60 px-3">
            Bench
          </span>
          <div className="h-px flex-1 bg-gradient-to-l from-transparent to-white/20" />
        </div>
        <div className="flex justify-center gap-2 md:gap-4 bg-surface/30 rounded-lg py-3 px-4">
          {bench.map((player, idx) => {
            const info = playersInfo.get(player.player_id)
            const teamName = info?.team ? teamsInfo.get(info.team) ?? '???' : '???'
            const { status, minutes } = getPlayerStatus(player.player_id)

            return (
              <PlayerStatusCard
                key={player.player_id}
                playerId={player.player_id}
                webName={info?.web_name ?? `P${player.player_id}`}
                teamName={teamName}
                teamId={info?.team ?? 0}
                points={player.points}
                multiplier={1} // Bench always has multiplier 1
                isCaptain={false}
                isViceCaptain={false}
                status={status}
                matchMinute={minutes}
                isBench
                animationDelay={(animationIndex + idx) * 50}
              />
            )
          })}
        </div>
      </div>
    </div>
  )
}
