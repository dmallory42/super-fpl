import { useMemo } from 'react'

interface Player {
  player_id: number
  web_name?: string
  element_type?: number
  team?: number
  multiplier: number
  is_captain?: boolean
  is_vice_captain?: boolean
  position: number
  points?: number
  effective_points?: number
  stats?: {
    total_points?: number
  }
  effective_ownership?: {
    ownership_percent: number
    captain_percent: number
    effective_ownership: number
    points_swing: number
  }
}

interface Team {
  id: number
  short_name: string
}

interface FormationPitchProps {
  players: Player[]
  teams: Record<number, Team>
  showEffectiveOwnership?: boolean
}

const positionColors: Record<number, string> = {
  1: 'bg-yellow-500', // GK
  2: 'bg-green-500',  // DEF
  3: 'bg-blue-500',   // MID
  4: 'bg-red-500',    // FWD
}

export function FormationPitch({ players, teams, showEffectiveOwnership = false }: FormationPitchProps) {
  const { starting, bench } = useMemo(() => {
    const sorted = [...players].sort((a, b) => a.position - b.position)

    const starting = sorted.filter(p => p.position <= 11)
    const bench = sorted.filter(p => p.position > 11)

    return { starting, bench }
  }, [players])

  // Group starting players by position type
  const gk = starting.filter(p => p.element_type === 1)
  const def = starting.filter(p => p.element_type === 2)
  const mid = starting.filter(p => p.element_type === 3)
  const fwd = starting.filter(p => p.element_type === 4)

  const rows = [gk, def, mid, fwd]

  return (
    <div className="bg-gradient-to-b from-green-800 to-green-600 rounded-lg p-4 relative overflow-hidden">
      {/* Pitch markings */}
      <div className="absolute inset-4 border-2 border-white/20 rounded pointer-events-none" />
      <div className="absolute left-1/2 top-4 bottom-4 w-px bg-white/20 pointer-events-none" />
      <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 border-2 border-white/20 rounded-full pointer-events-none" />

      {/* Formation display */}
      <div className="relative z-10 flex flex-col gap-6 py-4">
        {rows.map((row, rowIndex) => (
          <div key={rowIndex} className="flex justify-center gap-2 md:gap-4">
            {row.map((player) => (
              <PlayerCard
                key={player.player_id}
                player={player}
                teams={teams}
                showEO={showEffectiveOwnership}
              />
            ))}
          </div>
        ))}
      </div>

      {/* Bench */}
      <div className="mt-6 pt-4 border-t border-white/30">
        <div className="text-white/60 text-xs mb-3 text-center uppercase tracking-wider">Bench</div>
        <div className="flex justify-center gap-2 md:gap-4">
          {bench.map((player) => (
            <PlayerCard
              key={player.player_id}
              player={player}
              teams={teams}
              showEO={showEffectiveOwnership}
              isBench
            />
          ))}
        </div>
      </div>
    </div>
  )
}

interface PlayerCardProps {
  player: Player
  teams: Record<number, Team>
  showEO?: boolean
  isBench?: boolean
}

function PlayerCard({ player, teams, showEO = false, isBench = false }: PlayerCardProps) {
  const points = player.stats?.total_points ?? player.points ?? 0
  const displayPoints = player.effective_points ?? (points * player.multiplier)
  const teamName = player.team ? teams[player.team]?.short_name ?? '???' : '???'
  const posColor = positionColors[player.element_type ?? 3] || 'bg-gray-500'

  return (
    <div className={`flex flex-col items-center ${isBench ? 'opacity-60' : ''}`}>
      {/* Jersey/avatar with points */}
      <div className={`w-12 h-12 md:w-14 md:h-14 rounded-full flex items-center justify-center text-white font-bold relative ${posColor}`}>
        <span className="text-lg">{displayPoints}</span>
        {player.is_captain && (
          <span className="absolute -top-1 -right-1 bg-yellow-400 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow">
            C
          </span>
        )}
        {player.is_vice_captain && !player.is_captain && (
          <span className="absolute -top-1 -right-1 bg-gray-300 text-black rounded-full w-5 h-5 text-xs flex items-center justify-center font-bold shadow">
            V
          </span>
        )}
      </div>

      {/* Player name */}
      <div className="bg-white text-black text-xs px-2 py-0.5 rounded mt-1 max-w-[70px] truncate font-medium">
        {player.web_name || `P${player.player_id}`}
      </div>

      {/* Team */}
      <div className="text-white/70 text-xs">{teamName}</div>

      {/* Effective ownership */}
      {showEO && player.effective_ownership && (
        <div className={`text-xs mt-0.5 font-medium ${
          player.effective_ownership.points_swing > 0 ? 'text-red-300' : 'text-emerald-300'
        }`}>
          EO: {player.effective_ownership.effective_ownership.toFixed(0)}%
        </div>
      )}
    </div>
  )
}
