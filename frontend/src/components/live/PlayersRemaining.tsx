import { useMemo } from 'react'
import type { LiveManagerPlayer, GameweekFixtureStatus } from '../../api/client'

interface PlayersRemainingProps {
  players: LiveManagerPlayer[]
  playersMap: Map<number, { web_name: string; team: number; element_type: number }>
  fixtureData: GameweekFixtureStatus | undefined
  effectiveOwnership: Record<number, number> | undefined
}

// Position short names
const positionNames: Record<number, string> = {
  1: 'GK',
  2: 'DEF',
  3: 'MID',
  4: 'FWD',
}

export function PlayersRemaining({
  players,
  playersMap,
  fixtureData,
  effectiveOwnership,
}: PlayersRemainingProps) {
  const { playersLeft, playersFinished, avgPlayersLeft } = useMemo(() => {
    if (!fixtureData) {
      return { playersLeft: [], playersFinished: [], avgPlayersLeft: null }
    }

    const getFixtureStatus = (teamId: number) => {
      const fixture = fixtureData.fixtures.find(
        (f) => f.home_club_id === teamId || f.away_club_id === teamId
      )
      if (!fixture) return 'unknown'
      if (fixture.finished) return 'finished'
      if (fixture.started) return 'playing'
      return 'upcoming'
    }

    const starting = players.filter((p) => p.position <= 11)
    const left: typeof players = []
    const finished: typeof players = []

    for (const player of starting) {
      const info = playersMap.get(player.player_id)
      if (!info) continue
      const status = getFixtureStatus(info.team)
      if (status === 'finished') {
        finished.push(player)
      } else {
        left.push(player)
      }
    }

    let avgLeft: number | null = null
    if (effectiveOwnership) {
      let totalEO = 0
      for (const [playerIdStr, eo] of Object.entries(effectiveOwnership)) {
        const playerId = parseInt(playerIdStr, 10)
        const info = playersMap.get(playerId)
        if (!info) continue
        const status = getFixtureStatus(info.team)
        if (status !== 'finished') {
          totalEO += Math.min(eo, 100)
        }
      }
      avgLeft = totalEO / 100
    }

    return {
      playersLeft: left,
      playersFinished: finished,
      avgPlayersLeft: avgLeft,
    }
  }, [players, playersMap, fixtureData, effectiveOwnership])

  const yourPlayersLeft = playersLeft.length
  const totalStarting = 11
  const progress = ((totalStarting - yourPlayersLeft) / totalStarting) * 100

  const diff = avgPlayersLeft !== null ? yourPlayersLeft - avgPlayersLeft : null

  return (
    <div className="space-y-4">
      {/* Circular progress indicator */}
      <div className="flex items-center justify-center gap-6">
        {/* Progress ring */}
        <div className="relative w-20 h-20">
          {/* Background ring */}
          <svg className="w-full h-full -rotate-90" viewBox="0 0 36 36">
            <circle
              cx="18"
              cy="18"
              r="15.5"
              fill="none"
              stroke="currentColor"
              strokeWidth="3"
              className="text-surface-elevated"
            />
            {/* Progress arc */}
            <circle
              cx="18"
              cy="18"
              r="15.5"
              fill="none"
              stroke="currentColor"
              strokeWidth="3"
              strokeLinecap="round"
              strokeDasharray={`${progress} 100`}
              className="text-fpl-green transition-all duration-500"
            />
          </svg>
          {/* Center text */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span
              className={`font-mono text-xl font-bold ${yourPlayersLeft > 0 ? 'text-fpl-green' : 'text-foreground-muted'}`}
            >
              {yourPlayersLeft}
            </span>
            <span className="text-[8px] text-foreground-dim font-display uppercase tracking-wider">
              Left
            </span>
          </div>
        </div>

        {/* Stats */}
        <div className="space-y-2">
          <div className="flex items-baseline gap-2">
            <span className="font-mono text-2xl font-bold text-foreground">
              {playersFinished.length}
            </span>
            <span className="text-[10px] text-foreground-dim font-display uppercase tracking-wider">
              Finished
            </span>
          </div>

          {avgPlayersLeft !== null && (
            <div className="flex items-baseline gap-2">
              <span className="font-mono text-lg text-foreground-muted">
                {avgPlayersLeft.toFixed(1)}
              </span>
              <span className="text-[10px] text-foreground-dim font-display uppercase tracking-wider">
                10K Avg
              </span>
            </div>
          )}

          {/* Advantage indicator */}
          {diff !== null && Math.abs(diff) >= 0.5 && (
            <div
              className={`text-[10px] font-mono ${diff > 0 ? 'text-fpl-green' : 'text-destructive'}`}
            >
              {diff > 0 ? '▲' : '▼'} {Math.abs(diff).toFixed(1)} vs avg
            </div>
          )}
        </div>
      </div>

      {/* Players left to play */}
      {playersLeft.length > 0 && (
        <div className="pt-3 border-t border-border/50">
          <div className="text-[10px] text-foreground-dim font-display uppercase tracking-wider mb-2">
            Still to play
          </div>
          <div className="flex flex-wrap gap-1.5">
            {playersLeft.map((player, idx) => {
              const info = playersMap.get(player.player_id)
              const isPlaying = fixtureData?.fixtures.some((f) => {
                if (!info) return false
                return (
                  (f.home_club_id === info.team || f.away_club_id === info.team) &&
                  f.started &&
                  !f.finished
                )
              })
              const position = info?.element_type ? positionNames[info.element_type] : ''

              return (
                <div
                  key={player.player_id}
                  className={`
                    flex items-center gap-1 px-2 py-1 rounded text-xs animate-fade-in-up-fast opacity-0
                    ${
                      isPlaying
                        ? 'bg-gradient-to-r from-fpl-green/20 to-fpl-green/10 text-fpl-green ring-1 ring-fpl-green/30'
                        : 'bg-surface-elevated text-foreground-muted'
                    }
                  `}
                  style={{ animationDelay: `${idx * 30}ms` }}
                >
                  {position && (
                    <span
                      className={`text-[9px] font-display uppercase ${isPlaying ? 'text-fpl-green/70' : 'text-foreground-dim'}`}
                    >
                      {position}
                    </span>
                  )}
                  <span className={isPlaying ? 'font-medium' : ''}>
                    {info?.web_name || 'Unknown'}
                  </span>
                  {player.is_captain && <span className="text-yellow-400">©</span>}
                  {isPlaying && (
                    <span className="w-1.5 h-1.5 rounded-full bg-fpl-green animate-pulse" />
                  )}
                </div>
              )
            })}
          </div>
        </div>
      )}

      {playersLeft.length === 0 && (
        <div className="text-center text-foreground-muted text-sm py-2">All players finished</div>
      )}
    </div>
  )
}
