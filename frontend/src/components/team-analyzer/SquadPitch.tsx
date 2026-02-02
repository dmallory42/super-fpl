import { useMemo } from 'react'
import type { Pick, Player } from '../../types'
import { formatPrice } from '../../types'

interface SquadPitchProps {
  picks: Pick[]
  players: Map<number, Player>
  teams: Map<number, string>
}

interface PlayerSlotProps {
  pick: Pick
  player: Player | undefined
  teamName: string
  animationDelay?: number
}

function PlayerSlot({ pick, player, teamName, animationDelay = 0 }: PlayerSlotProps) {
  if (!player) {
    return (
      <div
        className="w-20 h-24 flex flex-col items-center justify-center text-foreground-dim animate-fade-in-up opacity-0"
        style={{ animationDelay: `${animationDelay}ms` }}
      >
        <div className="w-12 h-12 rounded-full bg-surface border-2 border-border" />
        <div className="text-xs mt-1">Unknown</div>
      </div>
    )
  }

  const isCaptain = pick.is_captain
  const isViceCaptain = pick.is_vice_captain

  return (
    <div
      className="w-20 flex flex-col items-center animate-fade-in-up opacity-0"
      style={{ animationDelay: `${animationDelay}ms` }}
    >
      <div className="relative group">
        {/* Jersey shape - unified green color */}
        <div
          className={`
            w-12 h-12 rounded-lg bg-gradient-to-b from-fpl-green to-emerald-600
            shadow-lg transform transition-transform duration-200
            group-hover:scale-110
            ${isCaptain ? 'animate-pulse-glow' : ''}
          `}
        >
          {/* Jersey details */}
          <div className="absolute inset-0 flex items-center justify-center">
            <div className="w-6 h-0.5 bg-white/30 rounded-full" />
          </div>
        </div>

        {/* Captain badge */}
        {isCaptain && (
          <div className="absolute -top-1 -right-1 w-5 h-5 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-full flex items-center justify-center text-xs font-bold text-black shadow-lg ring-2 ring-yellow-400/50">
            C
          </div>
        )}
        {isViceCaptain && !isCaptain && (
          <div className="absolute -top-1 -right-1 w-5 h-5 bg-gradient-to-br from-gray-300 to-gray-500 rounded-full flex items-center justify-center text-xs font-bold text-black shadow">
            V
          </div>
        )}
      </div>

      {/* Player info */}
      <div className="mt-1.5 text-center">
        <div className="bg-surface/90 backdrop-blur-sm px-2 py-0.5 rounded text-xs font-semibold text-foreground truncate max-w-[80px]">
          {player.web_name}
        </div>
        <div className="text-xs text-white/70 mt-0.5">{teamName}</div>
        <div className="text-xs text-white/50 font-mono">£{formatPrice(player.now_cost)}m</div>
      </div>
    </div>
  )
}

export function SquadPitch({ picks, players, teams }: SquadPitchProps) {
  const { startingXI, bench } = useMemo(() => {
    const starting = picks.filter(p => p.position <= 11).sort((a, b) => a.position - b.position)
    const benchPlayers = picks.filter(p => p.position > 11).sort((a, b) => a.position - b.position)
    return { startingXI: starting, bench: benchPlayers }
  }, [picks])

  const rows = useMemo(() => {
    const gk: Pick[] = []
    const def: Pick[] = []
    const mid: Pick[] = []
    const fwd: Pick[] = []

    for (const pick of startingXI) {
      const player = players.get(pick.element)
      if (!player) continue

      switch (player.element_type) {
        case 1: gk.push(pick); break
        case 2: def.push(pick); break
        case 3: mid.push(pick); break
        case 4: fwd.push(pick); break
      }
    }

    return [fwd, mid, def, gk]
  }, [startingXI, players])

  const getTeamName = (playerId: number) => {
    const player = players.get(playerId)
    if (!player) return ''
    return teams.get(player.team) || ''
  }

  let delayCounter = 0

  return (
    <div className="pitch-texture rounded-lg p-4 shadow-xl overflow-hidden">
      {/* Pitch markings */}
      <div className="relative border-2 border-white/20 rounded-lg p-4">
        {/* Center circle */}
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-24 h-24 border-2 border-white/10 rounded-full" />

        {/* Center line */}
        <div className="absolute top-4 bottom-4 left-1/2 w-px bg-white/10" />

        {/* Penalty area (top) */}
        <div className="absolute top-4 left-1/2 -translate-x-1/2 w-48 h-16 border-b-2 border-x-2 border-white/10 rounded-b-lg" />

        {/* Starting XI */}
        <div className="space-y-6 relative z-10">
          {rows.map((row, idx) => (
            <div key={idx} className="flex justify-center gap-2">
              {row.map((pick) => {
                const delay = delayCounter++ * 50
                return (
                  <PlayerSlot
                    key={pick.element}
                    pick={pick}
                    player={players.get(pick.element)}
                    teamName={getTeamName(pick.element)}
                    animationDelay={delay}
                  />
                )
              })}
            </div>
          ))}
        </div>
      </div>

      {/* Bench - Dugout style */}
      <div className="mt-4 pt-4 border-t-2 border-white/20">
        <div className="flex items-center justify-center gap-2 mb-3">
          <div className="h-px flex-1 bg-gradient-to-r from-transparent to-white/20" />
          <span className="text-xs font-display uppercase tracking-wider text-white/60 px-3">
            Bench
          </span>
          <div className="h-px flex-1 bg-gradient-to-l from-transparent to-white/20" />
        </div>
        <div className="flex justify-center gap-2 bg-surface/30 rounded-lg py-3 px-4">
          {bench.map((pick, idx) => (
            <PlayerSlot
              key={pick.element}
              pick={pick}
              player={players.get(pick.element)}
              teamName={getTeamName(pick.element)}
              animationDelay={(delayCounter + idx) * 50}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
