import { useState, useMemo, useEffect } from 'react'
import { useLiveManager, useLiveBonus } from '../hooks/useLive'
import { usePlayers } from '../hooks/usePlayers'
import { useCurrentGameweek } from '../hooks/useCurrentGameweek'
import { useLiveSamples, calculateComparisons } from '../hooks/useLiveSamples'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { LiveIndicator } from '../components/ui/LiveIndicator'
import { EmptyState, CalendarIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonTable, SkeletonPitch } from '../components/ui/SkeletonLoader'
import { LiveFormationPitch } from '../components/live/LiveFormationPitch'
import { ComparisonBars } from '../components/live/ComparisonBars'
import type { LiveManagerPlayer } from '../api/client'
import type { GameweekFixtureStatus } from '../api/client'

type PlayerInfo = { web_name: string; team: number; element_type: number }

/** Format rank for display (e.g., 1.2M, 450K, 5,000) */
function formatRank(rank: number): string {
  if (rank >= 1000000) return `${(rank / 1000000).toFixed(1)}M`
  if (rank >= 10000) return `${Math.round(rank / 1000)}K`
  if (rank >= 1000) return `${(rank / 1000).toFixed(1)}K`
  return rank.toLocaleString()
}

/**
 * Apply FPL auto-sub rules to the squad.
 * A player is subbed out if: match finished AND minutes = 0
 * Subs happen in bench order (positions 12-15)
 * Formation constraints: min 3 DEF, min 2 MID, min 1 FWD, exactly 1 GK
 */
function applyAutoSubs(
  players: LiveManagerPlayer[],
  playersInfo: Map<number, PlayerInfo>,
  fixtureData: GameweekFixtureStatus | undefined
): { players: LiveManagerPlayer[]; totalPoints: number; benchPoints: number; autoSubs: Array<{ out: number; in: number }> } {
  if (!fixtureData) {
    // Can't determine match status, return original
    const starting = players.filter(p => p.position <= 11)
    const bench = players.filter(p => p.position > 11)
    return {
      players,
      totalPoints: starting.reduce((sum, p) => sum + p.effective_points, 0),
      benchPoints: bench.reduce((sum, p) => sum + p.points, 0),
      autoSubs: [],
    }
  }

  // Helper to check if a player's match is finished
  const isMatchFinished = (playerId: number): boolean => {
    const info = playersInfo.get(playerId)
    if (!info) return false
    const teamId = info.team
    const fixture = fixtureData.fixtures.find(
      f => f.home_club_id === teamId || f.away_club_id === teamId
    )
    return fixture?.finished ?? false
  }

  // Helper to check if player didn't play (0 minutes and match finished)
  const didNotPlay = (player: LiveManagerPlayer): boolean => {
    const minutes = player.stats?.minutes ?? 0
    return minutes === 0 && isMatchFinished(player.player_id)
  }

  // Clone players array
  const result = players.map(p => ({ ...p }))

  // Separate starting XI and bench
  const starting = result.filter(p => p.position <= 11)
  const bench = result.filter(p => p.position > 11).sort((a, b) => a.position - b.position)

  // Track auto-subs made
  const autoSubs: Array<{ out: number; in: number }> = []

  // Check if adding a player maintains valid formation
  const canSubIn = (subPlayer: LiveManagerPlayer, outPlayer: LiveManagerPlayer): boolean => {
    const subInfo = playersInfo.get(subPlayer.player_id)
    const outInfo = playersInfo.get(outPlayer.player_id)
    if (!subInfo || !outInfo) return false

    // GK can only replace GK
    if (outInfo.element_type === 1) {
      return subInfo.element_type === 1
    }
    // GK can only come on for GK
    if (subInfo.element_type === 1) {
      return outInfo.element_type === 1
    }

    // Count formation excluding the player going out
    const activeStarting = starting.filter(p => !didNotPlay(p) && p.player_id !== outPlayer.player_id)
    const counts = { gk: 0, def: 0, mid: 0, fwd: 0 }
    for (const p of activeStarting) {
      const info = playersInfo.get(p.player_id)
      if (!info) continue
      switch (info.element_type) {
        case 1: counts.gk++; break
        case 2: counts.def++; break
        case 3: counts.mid++; break
        case 4: counts.fwd++; break
      }
    }

    // Add the incoming player
    switch (subInfo.element_type) {
      case 2: counts.def++; break
      case 3: counts.mid++; break
      case 4: counts.fwd++; break
    }

    // Check constraints: min 3 DEF, min 2 MID, min 1 FWD
    // Also max 5 DEF, max 5 MID, max 3 FWD (11 - 1 GK = 10 outfield)
    return counts.def >= 3 && counts.mid >= 2 && counts.fwd >= 1 &&
           counts.def <= 5 && counts.mid <= 5 && counts.fwd <= 3
  }

  // Process auto-subs
  for (const startingPlayer of starting) {
    if (!didNotPlay(startingPlayer)) continue

    // Find first eligible bench player
    for (const benchPlayer of bench) {
      // Skip if bench player already used or didn't play
      if (benchPlayer.position <= 11) continue // Already subbed in
      if (didNotPlay(benchPlayer)) continue
      if (!isMatchFinished(benchPlayer.player_id)) continue // Match not finished yet

      if (canSubIn(benchPlayer, startingPlayer)) {
        // Perform the sub: swap positions
        const oldStartingPos = startingPlayer.position
        startingPlayer.position = benchPlayer.position
        benchPlayer.position = oldStartingPos

        // Update multiplier and effective_points for the subbed-in player
        // Bench players have multiplier 0 in FPL API, set to 1 when subbed in
        benchPlayer.multiplier = 1
        benchPlayer.effective_points = benchPlayer.points

        // The player being subbed out gets 0 effective points and multiplier 0
        startingPlayer.multiplier = 0
        startingPlayer.effective_points = 0

        autoSubs.push({ out: startingPlayer.player_id, in: benchPlayer.player_id })
        break
      }
    }
  }

  // Recalculate points after subs
  const finalStarting = result.filter(p => p.position <= 11)
  const finalBench = result.filter(p => p.position > 11)

  const totalPoints = finalStarting.reduce((sum, p) => sum + p.effective_points, 0)
  const benchPoints = finalBench.reduce((sum, p) => sum + p.points, 0)

  return { players: result, totalPoints, benchPoints, autoSubs }
}

// Get manager ID from URL or localStorage
function getInitialManagerId(): { id: number | null; input: string } {
  const params = new URLSearchParams(window.location.search)
  const urlManager = params.get('manager')
  if (urlManager) {
    const id = parseInt(urlManager, 10)
    if (!isNaN(id) && id > 0) {
      return { id, input: urlManager }
    }
  }
  // Fallback to localStorage
  const savedId = localStorage.getItem('fpl_manager_id')
  if (savedId) {
    const id = parseInt(savedId, 10)
    if (!isNaN(id) && id > 0) {
      return { id, input: savedId }
    }
  }
  return { id: null, input: '' }
}

// Update URL with manager ID
function updateUrlManagerId(managerId: number | null) {
  const params = new URLSearchParams(window.location.search)
  if (managerId) {
    params.set('manager', String(managerId))
  } else {
    params.delete('manager')
  }
  const newUrl = `${window.location.pathname}?${params.toString()}`
  window.history.replaceState({}, '', newUrl)
}

export function Live() {
  const initial = getInitialManagerId()
  const [managerId, setManagerId] = useState<number | null>(initial.id)
  const [managerInput, setManagerInput] = useState(initial.input)

  // Auto-detect current gameweek
  const { data: gwData, isLoading: isLoadingGw, gameweekData } = useCurrentGameweek()

  const gameweek = gwData?.gameweek ?? null

  const { data: playersData } = usePlayers()
  const { data: liveManager, isLoading: isLoadingManager, error: managerError } = useLiveManager(gameweek, managerId)
  const { data: bonusData, isLoading: isLoadingBonus } = useLiveBonus(gameweek)
  const { data: samplesData } = useLiveSamples(gameweek)

  // Build player info maps
  const playersMap = useMemo(() => {
    if (!playersData?.players) return new Map()
    return new Map(playersData.players.map(p => [p.id, {
      web_name: p.web_name,
      team: p.team,
      element_type: p.element_type,
    }]))
  }, [playersData?.players])

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map(t => [t.id, t.short_name]))
  }, [playersData?.teams])

  // Apply auto-subs based on FPL rules
  const processedSquad = useMemo(() => {
    if (!liveManager?.players) return null
    return applyAutoSubs(liveManager.players, playersMap, gameweekData)
  }, [liveManager?.players, playersMap, gameweekData])

  // Calculate comparisons (use processed points after auto-subs)
  const comparisons = useMemo(() => {
    if (!processedSquad || !samplesData) return []
    return calculateComparisons(processedSquad.totalPoints, samplesData)
  }, [processedSquad, samplesData])

  // Get top 10k EO for display
  const top10kEO = samplesData?.samples?.top_10k?.effective_ownership

  // Calculate rank movement
  const rankMovement = useMemo(() => {
    if (!liveManager?.overall_rank) return null
    const current = liveManager.overall_rank
    const previous = liveManager.pre_gw_rank
    if (!previous) return { current, movement: 0, direction: 'none' as const }
    const movement = previous - current // positive = improved (lower rank is better)
    return {
      current,
      previous,
      movement,
      direction: movement > 0 ? 'up' as const : movement < 0 ? 'down' as const : 'none' as const,
    }
  }, [liveManager?.overall_rank, liveManager?.pre_gw_rank])

  const handleLoadManager = (id?: number) => {
    const managerId = id ?? parseInt(managerInput, 10)
    if (!isNaN(managerId) && managerId > 0) {
      setManagerId(managerId)
      setManagerInput(String(managerId))
      // Save to localStorage and URL
      localStorage.setItem('fpl_manager_id', String(managerId))
      updateUrlManagerId(managerId)
    }
  }

  const handleClearManager = () => {
    setManagerId(null)
    setManagerInput('')
    localStorage.removeItem('fpl_manager_id')
    updateUrlManagerId(null)
  }

  // Sync URL on initial load if we have a manager from localStorage but not URL
  useEffect(() => {
    if (managerId && !new URLSearchParams(window.location.search).get('manager')) {
      updateUrlManagerId(managerId)
    }
  }, [])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="animate-fade-in-up">
        <div className="flex items-center gap-3 mb-2">
          <h2 className="font-display text-2xl font-bold tracking-wider text-foreground">
            Live Tracker
          </h2>
          {gwData?.isLive && <LiveIndicator size="lg" />}
        </div>
        <p className="text-foreground-muted text-sm">
          {isLoadingGw ? (
            'Detecting gameweek...'
          ) : gwData ? (
            <>
              GW{gwData.gameweek} • {gwData.matchesPlayed}/{gwData.totalMatches} matches complete
              {gwData.matchesInProgress > 0 && ` • ${gwData.matchesInProgress} in progress`}
            </>
          ) : (
            'Track live points for your team during the gameweek.'
          )}
        </p>
      </div>

      {/* Manager Input - Collapsible when loaded */}
      {!managerId && (
        <div className="animate-fade-in-up animation-delay-100">
          <div className="flex gap-2 max-w-md">
            <input
              type="text"
              value={managerInput}
              onChange={(e) => setManagerInput(e.target.value)}
              placeholder="Enter your FPL Manager ID"
              className="input-broadcast flex-1"
              onKeyDown={(e) => e.key === 'Enter' && handleLoadManager()}
            />
            <button onClick={() => handleLoadManager()} className="btn-primary">
              Track
            </button>
          </div>
        </div>
      )}

      {/* Error State */}
      {managerError && (
        <div className="p-4 bg-destructive/10 border border-destructive/30 rounded-lg text-destructive animate-fade-in-up">
          {managerError.message || 'Failed to load live data'}
        </div>
      )}

      {/* Loading State */}
      {(isLoadingManager || isLoadingGw) && managerId && (
        <div className="space-y-6">
          <SkeletonStatGrid />
          <SkeletonPitch />
        </div>
      )}

      {/* Live Points Display */}
      {liveManager && !liveManager.error && gameweek && processedSquad && (
        <div className="space-y-6">
          {/* Stats Header */}
          <StatPanelGrid>
            <StatPanel
              label="Live Points"
              value={processedSquad.totalPoints}
              highlight
              animationDelay={0}
            />
            <StatPanel
              label="Bench Points"
              value={processedSquad.benchPoints}
              animationDelay={50}
            />
            {comparisons.length > 0 && comparisons[0] && (
              <StatPanel
                label={`vs ${comparisons[0].tierLabel}`}
                value={`${comparisons[0].difference >= 0 ? '+' : ''}${comparisons[0].difference.toFixed(0)}`}
                trend={comparisons[0].difference >= 0 ? 'up' : 'down'}
                animationDelay={100}
              />
            )}
            {rankMovement && (
              <StatPanel
                label="Live Rank"
                value={formatRank(rankMovement.current)}
                trend={rankMovement.direction === 'up' ? 'up' : rankMovement.direction === 'down' ? 'down' : undefined}
                subValue={rankMovement.previous ? (
                  rankMovement.direction === 'up'
                    ? `↑ ${formatRank(Math.abs(rankMovement.movement))} from ${formatRank(rankMovement.previous)}`
                    : rankMovement.direction === 'down'
                    ? `↓ ${formatRank(Math.abs(rankMovement.movement))} from ${formatRank(rankMovement.previous)}`
                    : `from ${formatRank(rankMovement.previous)}`
                ) : undefined}
                animationDelay={150}
              />
            )}
          </StatPanelGrid>

          {/* Auto-subs indicator */}
          {processedSquad.autoSubs.length > 0 && (
            <div className="flex items-center gap-2 text-sm text-foreground-muted animate-fade-in-up">
              <span className="text-fpl-green">↓↑</span>
              <span>{processedSquad.autoSubs.length} auto-sub{processedSquad.autoSubs.length > 1 ? 's' : ''} applied</span>
            </div>
          )}

          {/* Formation Pitch */}
          <BroadcastCard animationDelay={200}>
            <LiveFormationPitch
              players={processedSquad.players}
              playersInfo={playersMap}
              teamsInfo={teamsMap}
              fixtureData={gameweekData}
              effectiveOwnership={top10kEO}
            />
          </BroadcastCard>

          {/* Bottom Section: Comparisons + Bonus */}
          <div className="grid md:grid-cols-2 gap-6">
            {/* Comparison Bars */}
            <BroadcastCard title="vs Sample Averages" animationDelay={300}>
              <ComparisonBars
                userPoints={processedSquad.totalPoints}
                comparisons={comparisons}
                animationDelay={350}
              />
            </BroadcastCard>

            {/* Bonus Predictions */}
            <BroadcastCard title="Bonus Predictions" accentColor="purple" animationDelay={350}>
              {isLoadingBonus ? (
                <SkeletonTable rows={5} cols={3} />
              ) : bonusData?.bonus_predictions?.length ? (
                <div className="space-y-2">
                  {bonusData.bonus_predictions.slice(0, 10).map((bp, idx) => {
                    const player = playersData?.players.find(p => p.id === bp.player_id)
                    const teamName = player ? teamsMap.get(player.team) : ''

                    return (
                      <div
                        key={`${bp.player_id}-${bp.fixture_id}`}
                        className="flex items-center justify-between p-2 rounded bg-surface-elevated animate-fade-in-up opacity-0"
                        style={{ animationDelay: `${400 + idx * 30}ms` }}
                      >
                        <div>
                          <span className="text-foreground font-medium">
                            {player?.web_name || `Player ${bp.player_id}`}
                          </span>
                          <span className="text-xs text-foreground-dim ml-2">{teamName}</span>
                        </div>
                        <div className="flex items-center gap-3">
                          <span className="text-xs text-foreground-muted font-mono">{bp.bps} BPS</span>
                          <span
                            className={`
                              inline-flex items-center justify-center w-7 h-7 rounded-lg font-mono font-bold text-sm
                              ${
                                bp.predicted_bonus === 3
                                  ? 'bg-fpl-green/20 text-fpl-green'
                                  : bp.predicted_bonus === 2
                                  ? 'bg-foreground/10 text-foreground'
                                  : 'bg-orange-500/20 text-orange-400'
                              }
                            `}
                          >
                            +{bp.predicted_bonus}
                          </span>
                        </div>
                      </div>
                    )
                  })}
                </div>
              ) : (
                <div className="py-4 text-center text-foreground-muted text-sm">
                  No bonus predictions available
                </div>
              )}
            </BroadcastCard>
          </div>

          {/* Change Manager Link */}
          <div className="text-center">
            <button
              onClick={handleClearManager}
              className="text-sm text-foreground-muted hover:text-foreground transition-colors"
            >
              Change Manager ID
            </button>
          </div>
        </div>
      )}

      {/* Empty State */}
      {!managerId && !isLoadingGw && (
        <EmptyState
          icon={<CalendarIcon size={64} />}
          title="Track Your Live Points"
          description="Enter your FPL Manager ID to see live gameweek points, compare against top managers, and track bonus predictions."
        />
      )}
    </div>
  )
}
