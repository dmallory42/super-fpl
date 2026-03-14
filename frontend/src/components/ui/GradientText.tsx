import { ReactNode } from 'react'

type TeletextColor = 'cyan' | 'green' | 'yellow' | 'red' | 'blue' | 'magenta' | 'white'

interface TeletextTextProps {
  children: ReactNode
  color?: TeletextColor
  className?: string
  as?: 'span' | 'h1' | 'h2' | 'h3' | 'p' | 'div'
}

const colorMap: Record<TeletextColor, string> = {
  cyan: 'text-tt-cyan',
  green: 'text-tt-green',
  yellow: 'text-tt-yellow',
  red: 'text-tt-red',
  blue: 'text-tt-blue',
  magenta: 'text-tt-magenta',
  white: 'text-tt-white',
}

export function TeletextText({
  children,
  color = 'cyan',
  className = '',
  as: Component = 'span',
}: TeletextTextProps) {
  return <Component className={`${colorMap[color]} ${className}`}>{children}</Component>
}

// Backwards compatibility alias
export const GradientText = TeletextText
