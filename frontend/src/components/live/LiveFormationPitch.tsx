import { useMemo } from 'react'
import { PlayerStatusCard, type PlayerStatus } from './PlayerStatusCard'
import type { LiveManagerPlayer } from '../../api/client'
import type { GameweekFixtureStatus } from '../../api/client'
import { getFixturesForTeam, getTeamFixtureStatus } from '../../lib/fixtureMapping'

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
      starting: sorted.filter((p) => p.position <= 11),
      bench: sorted.filter((p) => p.position > 11),
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
        case 1:
          gk.push(player)
          break
        case 2:
          def.push(player)
          break
        case 3:
          mid.push(player)
          break
        case 4:
          fwd.push(player)
          break
      }
    }

    // Order: GK at top, then DEF, MID, FWD at bottom
    return [gk, def, mid, fwd]
  }, [starting, playersInfo])

  // Get player's fixture status
  const getPlayerStatus = (playerId: number): { status: PlayerStatus; minutes?: number } => {
    const info = playersInfo.get(playerId)
    if (!info || !fixtureData) return { status: 'unknown' }

    const status = getTeamFixtureStatus(fixtureData, info.team)
    if (status !== 'playing') return { status }

    const fixture = getFixturesForTeam(fixtureData, info.team)
      .sort((a, b) => (b.minutes ?? 0) - (a.minutes ?? 0))
      .find((f) => Boolean(f.started) && !Boolean(f.finished) && (f.minutes ?? 0) > 0)

    return { status: 'playing', minutes: fixture?.minutes }
  }

  // Build a flat index mapping for stable animation delays
  const animationDelays = useMemo(() => {
    const delays = new Map<number, number>()
    let index = 0
    for (const row of rows) {
      for (const player of row) {
        delays.set(player.player_id, index * 50)
        index++
      }
    }
    return { delays, totalStarters: index }
  }, [rows])

  return (
    <div className="pitch-texture rounded-lg p-3 md:p-4 relative overflow-hidden">
      {/* Pitch markings */}
      <div className="absolute inset-4 border-2 border-white/20 rounded pointer-events-none" />
      <div className="absolute left-1/2 top-4 bottom-4 w-px bg-white/10 pointer-events-none" />
      <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 border-2 border-white/10 rounded-full pointer-events-none" />

      {/* Goal area at top */}
      <div className="absolute top-4 left-1/2 -translate-x-1/2 w-32 h-12 border-b-2 border-x-2 border-white/10 rounded-b-lg pointer-events-none" />

      {/* Formation display */}
      <div className="relative z-10 flex flex-col gap-4 md:gap-5 py-3 md:py-4">
        {rows.map((row, rowIndex) => (
          <div key={rowIndex} className="w-full flex justify-center gap-3 md:gap-4">
            {row.map((player) => {
              const info = playersInfo.get(player.player_id)
              const teamName = info?.team ? (teamsInfo.get(info.team) ?? '???') : '???'
              const { status, minutes } = getPlayerStatus(player.player_id)
              const delay = animationDelays.delays.get(player.player_id) ?? 0

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
      <div className="mt-4 md:mt-6 pt-3 md:pt-4 border-t-2 border-white/20">
        <div className="flex items-center justify-center gap-2 mb-3">
          <div className="h-px flex-1 bg-gradient-to-r from-transparent to-white/20" />
          <span className="font-display text-xs uppercase tracking-wider text-white/60 px-3">
            Bench
          </span>
          <div className="h-px flex-1 bg-gradient-to-l from-transparent to-white/20" />
        </div>
        <div className="flex justify-center gap-3 md:gap-4 bg-surface/30 rounded-lg py-2.5 md:py-3 px-2.5 md:px-4">
          {bench.map((player, idx) => {
            const info = playersInfo.get(player.player_id)
            const teamName = info?.team ? (teamsInfo.get(info.team) ?? '???') : '???'
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
                animationDelay={(animationDelays.totalStarters + idx) * 50}
              />
            )
          })}
        </div>
      </div>
    </div>
  )
}
