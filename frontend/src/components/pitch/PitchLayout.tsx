import type { ReactNode } from 'react'

type PitchVariant = 'formation' | 'squad'

interface PitchLayoutProps {
  rows: ReactNode[][]
  bench: ReactNode[]
  variant?: PitchVariant
}

export function PitchLayout({ rows, bench, variant = 'formation' }: PitchLayoutProps) {
  const isSquad = variant === 'squad'

  return (
    <div
      className={`pitch-texture rounded-lg p-4 overflow-hidden ${isSquad ? 'shadow-xl' : 'relative'}`}
    >
      {isSquad ? (
        <div className="relative border-2 border-white/20 rounded-lg p-4">
          <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-24 h-24 border-2 border-white/10 rounded-full" />
          <div className="absolute top-4 bottom-4 left-1/2 w-px bg-white/10" />
          <div className="absolute top-4 left-1/2 -translate-x-1/2 w-48 h-16 border-b-2 border-x-2 border-white/10 rounded-b-lg" />
          <div className="space-y-6 relative z-10">
            {rows.map((row, rowIndex) => (
              <div key={rowIndex} className="flex justify-center gap-2">
                {row}
              </div>
            ))}
          </div>
        </div>
      ) : (
        <>
          <div className="absolute inset-4 border-2 border-white/20 rounded pointer-events-none" />
          <div className="absolute left-1/2 top-4 bottom-4 w-px bg-white/10 pointer-events-none" />
          <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 border-2 border-white/10 rounded-full pointer-events-none" />
          <div className="absolute bottom-4 left-1/2 -translate-x-1/2 w-32 h-12 border-t-2 border-x-2 border-white/10 rounded-t-lg pointer-events-none" />
          <div className="relative z-10 flex flex-col gap-6 py-4">
            {rows.map((row, rowIndex) => (
              <div key={rowIndex} className="flex justify-center gap-2 md:gap-4">
                {row}
              </div>
            ))}
          </div>
        </>
      )}

      <div className={`${isSquad ? 'mt-4' : 'mt-6'} pt-4 border-t-2 border-white/20`}>
        <div className="flex items-center justify-center gap-2 mb-3">
          <div className="h-px flex-1 bg-gradient-to-r from-transparent to-white/20" />
          <span className="font-display text-xs uppercase tracking-wider text-white/60 px-3">
            Bench
          </span>
          <div className="h-px flex-1 bg-gradient-to-l from-transparent to-white/20" />
        </div>
        <div
          className={`flex justify-center ${isSquad ? 'gap-2' : 'gap-2 md:gap-4'} bg-surface/30 rounded-lg py-3 px-4`}
        >
          {bench}
        </div>
      </div>
    </div>
  )
}
