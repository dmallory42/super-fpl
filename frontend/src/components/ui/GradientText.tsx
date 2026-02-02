import { ReactNode } from 'react'

interface GradientTextProps {
  children: ReactNode
  variant?: 'primary' | 'accent' | 'custom'
  className?: string
  as?: 'span' | 'h1' | 'h2' | 'h3' | 'p' | 'div'
  customGradient?: string
}

export function GradientText({
  children,
  variant = 'primary',
  className = '',
  as: Component = 'span',
  customGradient,
}: GradientTextProps) {
  const gradients = {
    primary: 'from-fpl-green via-emerald-400 to-fpl-green',
    accent: 'from-fpl-purple via-pink-400 to-highlight',
    custom: customGradient || 'from-fpl-green to-fpl-purple',
  }

  return (
    <Component
      className={`
        bg-gradient-to-r ${gradients[variant]}
        bg-clip-text text-transparent
        ${className}
      `}
    >
      {children}
    </Component>
  )
}
