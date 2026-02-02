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
}

function PlayerSlot({ pick, player, teamName }: PlayerSlotProps) {
  if (!player) {
    return (
      <div className="w-20 h-24 flex flex-col items-center justify-center text-gray-500">
        <div className="w-12 h-12 rounded-full bg-gray-700 border-2 border-gray-600" />
        <div className="text-xs mt-1">Unknown</div>
      </div>
    )
  }

  const isCaptain = pick.is_captain
  const isViceCaptain = pick.is_vice_captain

  return (
    <div className="w-20 flex flex-col items-center">
      <div className="relative">
        <div className="w-12 h-12 rounded-full bg-emerald-600 shadow-lg"></div>
        {isCaptain && (
          <div className="absolute -top-1 -right-1 w-5 h-5 bg-yellow-400 rounded-full flex items-center justify-center text-xs font-bold text-black shadow">
            C
          </div>
        )}
        {isViceCaptain && (
          <div className="absolute -top-1 -right-1 w-5 h-5 bg-gray-300 rounded-full flex items-center justify-center text-xs font-bold text-black shadow">
            V
          </div>
        )}
      </div>
      <div className="mt-1 text-center">
        <div className="text-xs font-semibold text-white truncate max-w-[80px]">{player.web_name}</div>
        <div className="text-xs text-gray-400">{teamName}</div>
        <div className="text-xs text-gray-500">£{formatPrice(player.now_cost)}m</div>
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
    // Group by position type
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

  return (
    <div className="bg-gradient-to-b from-green-800 to-green-900 rounded-lg p-4 shadow-xl">
      {/* Pitch markings */}
      <div className="relative border-2 border-white/30 rounded-lg p-4">
        {/* Center circle */}
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-24 h-24 border border-white/20 rounded-full" />

        {/* Starting XI */}
        <div className="space-y-4">
          {rows.map((row, idx) => (
            <div key={idx} className="flex justify-center gap-2">
              {row.map(pick => (
                <PlayerSlot
                  key={pick.element}
                  pick={pick}
                  player={players.get(pick.element)}
                  teamName={getTeamName(pick.element)}
                />
              ))}
            </div>
          ))}
        </div>
      </div>

      {/* Bench */}
      <div className="mt-4 pt-4 border-t border-white/20">
        <div className="text-xs text-white/60 mb-2 text-center">BENCH</div>
        <div className="flex justify-center gap-2">
          {bench.map(pick => (
            <PlayerSlot
              key={pick.element}
              pick={pick}
              player={players.get(pick.element)}
              teamName={getTeamName(pick.element)}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
