import { cn } from '@/lib/utils'
import { getPositionName, type Position } from '@/types/player'

interface PositionBadgeProps {
  elementType: number
  className?: string
}

const positionColors: Record<Position, string> = {
  GKP: 'bg-yellow-500 text-yellow-950',
  DEF: 'bg-green-500 text-green-950',
  MID: 'bg-blue-500 text-blue-950',
  FWD: 'bg-red-500 text-red-950',
}

export function PositionBadge({ elementType, className }: PositionBadgeProps) {
  const position = getPositionName(elementType)
  const colorClass = positionColors[position]

  return (
    <span
      className={cn(
        'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
        colorClass,
        className
      )}
    >
      {position}
    </span>
  )
}
