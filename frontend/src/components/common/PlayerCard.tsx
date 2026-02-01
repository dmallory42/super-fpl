import { type Player, getPositionName, formatPrice } from '../../types'

interface PlayerCardProps {
  player: Player
  teamName?: string
}

const positionColors: Record<string, string> = {
  GKP: 'bg-yellow-500',
  DEF: 'bg-green-500',
  MID: 'bg-blue-500',
  FWD: 'bg-red-500',
}

export function PlayerCard({ player, teamName }: PlayerCardProps) {
  const position = getPositionName(player.element_type)

  return (
    <div className="flex items-center gap-3 p-3 bg-gray-800 rounded-lg">
      <div className={`w-10 h-10 rounded-full ${positionColors[position]} flex items-center justify-center text-white font-bold text-xs`}>
        {position}
      </div>
      <div className="flex-1 min-w-0">
        <div className="font-semibold text-white truncate">{player.web_name}</div>
        <div className="text-sm text-gray-400">{teamName || `Team ${player.team}`}</div>
      </div>
      <div className="text-right">
        <div className="font-bold text-white">{player.total_points} pts</div>
        <div className="text-sm text-gray-400">£{formatPrice(player.now_cost)}m</div>
      </div>
    </div>
  )
}
