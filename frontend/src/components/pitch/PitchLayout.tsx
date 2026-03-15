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
    <div className="pitch-texture p-4 overflow-hidden relative">
      {/* Dim green field lines */}
      <div className="absolute inset-4 border-2 border-[#006600] pointer-events-none" />
      <div className="absolute left-1/2 top-4 bottom-4 w-px bg-[#006600] pointer-events-none" />
      <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 border-2 border-[#006600] pointer-events-none" />
      {isSquad ? (
        <div className="absolute top-4 left-1/2 -translate-x-1/2 w-48 h-16 border-b-2 border-x-2 border-[#006600] pointer-events-none" />
      ) : (
        <div className="absolute bottom-4 left-1/2 -translate-x-1/2 w-32 h-12 border-t-2 border-x-2 border-[#006600] pointer-events-none" />
      )}

      {/* Formation rows */}
      <div className={`relative z-10 flex flex-col ${isSquad ? 'gap-6' : 'gap-6 py-4'}`}>
        {rows.map((row, rowIndex) => (
          <div
            key={rowIndex}
            className={`flex justify-center ${isSquad ? 'gap-2' : 'gap-2 md:gap-4'}`}
          >
            {row}
          </div>
        ))}
      </div>

      {/* Bench */}
      <div className="mt-6 pt-4 border-t-2 border-[#006600]">
        <div className="flex items-center justify-center gap-2 mb-3">
          <span className="text-tt-cyan text-sm">
            {'─'.repeat(3)} BENCH {'─'.repeat(3)}
          </span>
        </div>
        <div className={`flex justify-center ${isSquad ? 'gap-2' : 'gap-2 md:gap-4'} py-3 px-4`}>
          {bench}
        </div>
      </div>
    </div>
  )
}
