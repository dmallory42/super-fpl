import { useState, useMemo } from 'react'
import { usePlayers } from '../hooks/usePlayers'
import { useManager, useManagerPicks, useManagerHistory } from '../hooks/useManager'
import { ManagerSearch } from '../components/team-analyzer/ManagerSearch'
import { SquadPitch } from '../components/team-analyzer/SquadPitch'
import { SquadStats } from '../components/team-analyzer/SquadStats'
import { SeasonReview } from '../components/team-analyzer/SeasonReview'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { EmptyState, TrophyIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonPitch, SkeletonCard } from '../components/ui/SkeletonLoader'
import { GradientText } from '../components/ui/GradientText'

export function TeamAnalyzer() {
  const [managerId, setManagerId] = useState<number | null>(null)
  const { data: playersData, isLoading: playersLoading } = usePlayers()
  const { data: manager, isLoading: managerLoading, error: managerError } = useManager(managerId)
  const { data: picks, isLoading: picksLoading, error: picksError } = useManagerPicks(
    managerId,
    manager?.current_event ?? null
  )
  const { data: history, isLoading: historyLoading } = useManagerHistory(managerId)

  const playersMap = useMemo(() => {
    if (!playersData?.players) return new Map()
    return new Map(playersData.players.map(p => [p.id, p]))
  }, [playersData?.players])

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  const handleSearch = (id: number) => {
    setManagerId(id)
  }

  const isLoading = playersLoading || managerLoading || picksLoading || historyLoading
  const error = managerError || picksError

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="animate-fade-in-up">
        <h2 className="font-display text-2xl font-bold tracking-wider text-foreground mb-2">
          Season Review
        </h2>
        <p className="text-foreground-muted text-sm mb-4">
          Enter your FPL Manager ID to review your season performance. Find it in your FPL URL:
          <span className="font-mono text-fpl-green ml-1">
            fantasy.premierleague.com/entry/<strong>123456</strong>/event/1
          </span>
        </p>
        <ManagerSearch onSearch={handleSearch} isLoading={isLoading} />
      </div>

      {/* Error State */}
      {error && (
        <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
          <div className="flex items-center gap-2">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{error.message || 'Failed to load manager data'}</span>
          </div>
        </div>
      )}

      {/* Loading State */}
      {isLoading && managerId && (
        <div className="space-y-6">
          <SkeletonCard />
          <SkeletonStatGrid />
          <SkeletonPitch />
        </div>
      )}

      {/* Manager Card */}
      {manager && !isLoading && (
        <BroadcastCard animationDelay={100}>
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
              <h3 className="font-display text-xl font-bold text-foreground tracking-wider">
                {manager.name}
              </h3>
              <p className="text-foreground-muted">
                {manager.player_first_name} {manager.player_last_name}
              </p>
            </div>
            <div className="text-right">
              <div className="font-mono text-3xl font-bold">
                <GradientText>{manager.summary_overall_points}</GradientText>
              </div>
              <div className="text-sm text-foreground-muted">
                Rank: <span className="font-mono">{manager.summary_overall_rank?.toLocaleString() ?? 'N/A'}</span>
              </div>
            </div>
          </div>
        </BroadcastCard>
      )}

      {/* Squad Stats */}
      {picks && picks.picks && !isLoading && (
        <>
          <SquadStats
            picks={picks.picks}
            players={playersMap}
            entryHistory={picks.entry_history}
          />

          <SquadPitch
            picks={picks.picks}
            players={playersMap}
            teams={teamsMap}
          />

          {/* Active Chip */}
          {picks.active_chip && (
            <div className="p-4 bg-gradient-to-r from-fpl-purple/20 to-fpl-purple/5 border border-fpl-purple/30 rounded-lg text-center animate-fade-in-up">
              <span className="text-fpl-purple font-display uppercase tracking-wider">Active Chip: </span>
              <span className="text-foreground font-bold font-display uppercase tracking-wider">{picks.active_chip}</span>
            </div>
          )}
        </>
      )}

      {/* Season Review */}
      {manager && history && !isLoading && (
        <SeasonReview history={history} />
      )}

      {/* Empty State */}
      {!manager && !isLoading && !error && (
        <EmptyState
          icon={<TrophyIcon size={64} />}
          title="Enter Your Manager ID"
          description="Search for your FPL team to see your complete season review, squad analysis, and rank progression."
        />
      )}
    </div>
  )
}
