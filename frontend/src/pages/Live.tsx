import { useState, useMemo, useEffect } from 'react'
import { useLiveData, useLiveManager, useLiveBonus } from '../hooks/useLive'
import { usePlayers } from '../hooks/usePlayers'
import { useCurrentGameweek } from '../hooks/useCurrentGameweek'
import { useLiveSamples, calculateComparisons } from '../hooks/useLiveSamples'
import { usePredictions } from '../hooks/usePredictions'
import { StatPanel, StatPanelGrid } from '../components/ui/StatPanel'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { LiveIndicator } from '../components/ui/LiveIndicator'
import { EmptyState, CalendarIcon } from '../components/ui/EmptyState'
import { SkeletonStatGrid, SkeletonPitch } from '../components/ui/SkeletonLoader'
import { LiveFormationPitch } from '../components/live/LiveFormationPitch'
import {
  ComparisonBars,
  type PlayerImpact,
  type ComparisonTier,
} from '../components/live/ComparisonBars'
import { FixtureScores } from '../components/live/FixtureScores'
import { CaptainBattle } from '../components/live/CaptainBattle'
import { PlayersRemaining } from '../components/live/PlayersRemaining'
import { RankProjection } from '../components/live/RankProjection'
import { VarianceAnalysis } from '../components/live/VarianceAnalysis'
import { FixtureThreatIndex } from '../components/live/FixtureThreatIndex'
import { DifferentialAnalysis } from '../components/live/DifferentialAnalysis'
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
): {
  players: LiveManagerPlayer[]
  totalPoints: number
  benchPoints: number
  autoSubs: Array<{ out: number; in: number }>
} {
  if (!fixtureData) {
    // Can't determine match status, return original
    const starting = players.filter((p) => p.position <= 11)
    const bench = players.filter((p) => p.position > 11)
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
      (f) => f.home_club_id === teamId || f.away_club_id === teamId
    )
    return fixture?.finished ?? false
  }

  // Helper to check if player didn't play (0 minutes and match finished)
  const didNotPlay = (player: LiveManagerPlayer): boolean => {
    const minutes = player.stats?.minutes ?? 0
    return minutes === 0 && isMatchFinished(player.player_id)
  }

  // Clone players array
  const result = players.map((p) => ({ ...p }))

  // Separate starting XI and bench
  const starting = result.filter((p) => p.position <= 11)
  const bench = result.filter((p) => p.position > 11).sort((a, b) => a.position - b.position)

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
    const activeStarting = starting.filter(
      (p) => !didNotPlay(p) && p.player_id !== outPlayer.player_id
    )
    const counts = { gk: 0, def: 0, mid: 0, fwd: 0 }
    for (const p of activeStarting) {
      const info = playersInfo.get(p.player_id)
      if (!info) continue
      switch (info.element_type) {
        case 1:
          counts.gk++
          break
        case 2:
          counts.def++
          break
        case 3:
          counts.mid++
          break
        case 4:
          counts.fwd++
          break
      }
    }

    // Add the incoming player
    switch (subInfo.element_type) {
      case 2:
        counts.def++
        break
      case 3:
        counts.mid++
        break
      case 4:
        counts.fwd++
        break
    }

    // Check constraints: min 3 DEF, min 2 MID, min 1 FWD
    // Also max 5 DEF, max 5 MID, max 3 FWD (11 - 1 GK = 10 outfield)
    return (
      counts.def >= 3 &&
      counts.mid >= 2 &&
      counts.fwd >= 1 &&
      counts.def <= 5 &&
      counts.mid <= 5 &&
      counts.fwd <= 3
    )
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
  const finalStarting = result.filter((p) => p.position <= 11)
  const finalBench = result.filter((p) => p.position > 11)

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
  const [comparisonTier, setComparisonTier] = useState<ComparisonTier>('top_10k')

  // Auto-detect current gameweek
  const { data: gwData, isLoading: isLoadingGw, gameweekData } = useCurrentGameweek()

  const gameweek = gwData?.gameweek ?? null

  const { data: playersData } = usePlayers()
  const {
    data: liveManager,
    isLoading: isLoadingManager,
    error: managerError,
  } = useLiveManager(gameweek, managerId)
  const { data: samplesData } = useLiveSamples(gameweek)
  const { data: liveData } = useLiveData(gameweek)
  const { data: bonusData } = useLiveBonus(gameweek)
  const { data: predictionsData } = usePredictions(gameweek)

  // Build player info maps
  const playersMap = useMemo(() => {
    if (!playersData?.players) return new Map()
    return new Map(
      playersData.players.map((p) => [
        p.id,
        {
          web_name: p.web_name,
          team: p.team,
          element_type: p.element_type,
        },
      ])
    )
  }, [playersData?.players])

  const teamsMap = useMemo(() => {
    if (!playersData?.teams) return new Map()
    return new Map(playersData.teams.map((t) => [t.id, t.short_name]))
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

  // Get EO for selected comparison tier
  const tierEO = samplesData?.samples?.[comparisonTier]?.effective_ownership
  // Also keep top 10k EO for pitch display
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
      direction:
        movement > 0 ? ('up' as const) : movement < 0 ? ('down' as const) : ('none' as const),
    }
  }, [liveManager?.overall_rank, liveManager?.pre_gw_rank])

  // Calculate player impacts on rank (differentials)
  // Impact = your points from player - average tier points from player
  // Owned: impact = effective_points * (1 - EO / (100 * multiplier))
  // Not owned: impact = -points * EO / 100 (you got 0, others got points * EO%)
  const playerImpacts = useMemo((): PlayerImpact[] => {
    if (!processedSquad || !tierEO || !playersMap) return []

    const ownedPlayerIds = new Set(processedSquad.players.map((p) => p.player_id))
    const ownedImpacts: PlayerImpact[] = []
    const notOwnedImpacts: PlayerImpact[] = []

    // Calculate impact for owned players (in starting XI only)
    for (const player of processedSquad.players.filter((p) => p.position <= 11)) {
      const info = playersMap.get(player.player_id)
      if (!info) continue

      const eo = tierEO[player.player_id] ?? 0
      const multiplier = player.multiplier || 1 // 1 = normal, 2 = captain, 3 = triple captain
      const yourExposure = multiplier * 100 // 100% normal, 200% captain, 300% TC
      const relativeEO = yourExposure - eo // positive = you have more exposure than field

      // Impact formula accounts for captaincy:
      // If you captain (2x) a player with 100% EO, you still gain vs field
      // If you captain (2x) a player with 200% EO, you're even with field
      const impact = player.effective_points * (1 - eo / (100 * multiplier))

      ownedImpacts.push({
        playerId: player.player_id,
        name: info.web_name,
        points: player.effective_points,
        eo,
        relativeEO,
        impact,
        owned: true,
      })
    }

    // Calculate impact for NOT owned players with high EO (these hurt your rank)
    if (liveData?.elements) {
      for (const element of liveData.elements) {
        if (ownedPlayerIds.has(element.id)) continue // Skip owned players

        const eo = tierEO[element.id]
        if (!eo || eo < 15) continue // Only care about reasonably popular players

        const points = element.stats?.total_points ?? 0
        if (points <= 2) continue // Only care about players who actually scored

        const info = playersMap.get(element.id)
        if (!info) continue

        // You got 0 from this player, others in tier got points * EO/100
        const impact = -((points * eo) / 100)
        const relativeEO = -eo // 0% yours - eo% theirs = negative

        notOwnedImpacts.push({
          playerId: element.id,
          name: info.web_name,
          points,
          eo,
          relativeEO,
          impact,
          owned: false,
        })
      }
    }

    // Sort by impact
    const gainers = ownedImpacts
      .filter((p) => p.impact > 0.5)
      .sort((a, b) => b.impact - a.impact)
      .slice(0, 5)
    // For losers, combine owned (negative impact) and not owned (all negative)
    const allLosers = [...ownedImpacts.filter((p) => p.impact < -0.5), ...notOwnedImpacts]
      .sort((a, b) => a.impact - b.impact)
      .slice(0, 5)

    return [...gainers, ...allLosers]
  }, [processedSquad, tierEO, playersMap, liveData])

  // Get user's captain info
  const userCaptain = useMemo(() => {
    if (!processedSquad) return null
    const captain = processedSquad.players.find((p) => p.is_captain)
    if (!captain) return null
    return {
      playerId: captain.player_id,
      points: captain.effective_points, // Already doubled
    }
  }, [processedSquad])

  // Build predictions map for variance analysis
  const predictionsMap = useMemo(() => {
    if (!predictionsData?.predictions) return new Map<number, number>()
    return new Map(predictionsData.predictions.map((p) => [p.player_id, p.predicted_points]))
  }, [predictionsData?.predictions])

  // Calculate variance analysis data
  const varianceData = useMemo(() => {
    if (!processedSquad || predictionsMap.size === 0) return null

    const startingXI = processedSquad.players.filter((p) => p.position <= 11)
    const players: Array<{ playerId: number; name: string; predicted: number; actual: number }> = []

    let totalPredicted = 0
    let totalActual = 0

    for (const player of startingXI) {
      const info = playersMap.get(player.player_id)
      const predicted = predictionsMap.get(player.player_id) ?? 0
      const actual = player.effective_points

      // Adjust prediction for captaincy multiplier
      const adjustedPredicted = predicted * (player.multiplier || 1)

      totalPredicted += adjustedPredicted
      totalActual += actual

      players.push({
        playerId: player.player_id,
        name: info?.web_name ?? 'Unknown',
        predicted: Math.round(adjustedPredicted * 10) / 10,
        actual,
      })
    }

    return {
      players,
      totalPredicted: Math.round(totalPredicted),
      totalActual,
    }
  }, [processedSquad, predictionsMap, playersMap])

  // Calculate fixture impacts for threat index (user points vs tier avg per fixture)
  const fixtureImpacts = useMemo(() => {
    if (!gameweekData || !processedSquad || !tierEO || !liveData?.elements) return []

    const startingXI = processedSquad.players.filter((p) => p.position <= 11)

    return gameweekData.fixtures.map((fixture) => {
      const homeTeam = teamsMap.get(fixture.home_club_id) ?? '???'
      const awayTeam = teamsMap.get(fixture.away_club_id) ?? '???'

      // Calculate user's points from this fixture
      let userPoints = 0
      let hasUserPlayer = false
      for (const player of startingXI) {
        const info = playersMap.get(player.player_id)
        if (!info) continue
        if (info.team === fixture.home_club_id || info.team === fixture.away_club_id) {
          userPoints += player.effective_points
          hasUserPlayer = true
        }
      }

      // Calculate tier average points from this fixture
      let tierAvgPoints = 0
      for (const element of liveData.elements) {
        const info = playersMap.get(element.id)
        if (!info) continue
        if (info.team === fixture.home_club_id || info.team === fixture.away_club_id) {
          const eo = tierEO[element.id] ?? 0
          const points = element.stats?.total_points ?? 0
          tierAvgPoints += (points * eo) / 100
        }
      }

      return {
        fixtureId: fixture.id,
        homeTeam,
        awayTeam,
        userPoints,
        tierAvgPoints,
        impact: userPoints - tierAvgPoints,
        isLive: fixture.started && !fixture.finished,
        isFinished: fixture.finished,
        hasUserPlayer,
      }
    })
  }, [gameweekData, processedSquad, tierEO, liveData, playersMap, teamsMap])

  // Calculate differential analysis data
  const differentialData = useMemo(() => {
    if (!processedSquad || !tierEO) return []

    const startingXI = processedSquad.players.filter((p) => p.position <= 11)
    const players: Array<{
      playerId: number
      name: string
      points: number
      eo: number
      impact: number
      multiplier: number
    }> = []

    for (const player of startingXI) {
      const info = playersMap.get(player.player_id)
      if (!info) continue

      const eo = tierEO[player.player_id] ?? 0
      const multiplier = player.multiplier || 1
      const impact = player.effective_points * (1 - eo / (100 * multiplier))

      players.push({
        playerId: player.player_id,
        name: info.web_name,
        points: player.effective_points,
        eo,
        impact,
        multiplier,
      })
    }

    return players
  }, [processedSquad, tierEO, playersMap])

  // Get tier label for differential analysis
  const tierLabels: Record<ComparisonTier, string> = {
    top_10k: '10K',
    top_100k: '100K',
    top_1m: '1M',
    overall: 'All',
  }

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
              aria-label="Manager ID"
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
                trend={
                  rankMovement.direction === 'up'
                    ? 'up'
                    : rankMovement.direction === 'down'
                      ? 'down'
                      : undefined
                }
                subValue={
                  rankMovement.previous
                    ? rankMovement.direction === 'up'
                      ? `↑ ${formatRank(Math.abs(rankMovement.movement))} from ${formatRank(rankMovement.previous)}`
                      : rankMovement.direction === 'down'
                        ? `↓ ${formatRank(Math.abs(rankMovement.movement))} from ${formatRank(rankMovement.previous)}`
                        : `from ${formatRank(rankMovement.previous)}`
                    : undefined
                }
                animationDelay={150}
              />
            )}
          </StatPanelGrid>

          {/* Auto-subs indicator */}
          {processedSquad.autoSubs.length > 0 && (
            <div className="flex items-center gap-2 text-sm text-foreground-muted animate-fade-in-up">
              <span className="text-fpl-green">↓↑</span>
              <span>
                {processedSquad.autoSubs.length} auto-sub
                {processedSquad.autoSubs.length > 1 ? 's' : ''} applied
              </span>
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

          {/* Bottom Section: Stats Grid */}
          <div className="grid md:grid-cols-2 gap-6">
            {/* Comparison Bars */}
            <BroadcastCard title="vs Sample Averages" animationDelay={300}>
              <ComparisonBars
                userPoints={processedSquad.totalPoints}
                comparisons={comparisons}
                playerImpacts={playerImpacts}
                selectedTier={comparisonTier}
                onTierChange={setComparisonTier}
                animationDelay={350}
              />
            </BroadcastCard>

            {/* Captain Battle */}
            <BroadcastCard title="Captain Battle" accentColor="purple" animationDelay={350}>
              <CaptainBattle
                userCaptainId={userCaptain?.playerId}
                samples={samplesData?.samples}
                playersMap={playersMap}
              />
            </BroadcastCard>

            {/* Players Remaining */}
            <BroadcastCard title="Players Left" animationDelay={400}>
              <PlayersRemaining
                players={processedSquad.players}
                playersMap={playersMap}
                fixtureData={gameweekData}
                effectiveOwnership={top10kEO}
              />
            </BroadcastCard>

            {/* Fixture Scores */}
            <BroadcastCard title="Fixtures" accentColor="purple" animationDelay={450}>
              <FixtureScores
                fixtureData={gameweekData}
                teamsMap={teamsMap}
                liveElements={liveData?.elements}
                playersMap={playersMap}
                bonusPredictions={bonusData?.bonus_predictions}
              />
            </BroadcastCard>
          </div>

          {/* Advanced Analytics Section */}
          <div className="grid md:grid-cols-2 gap-6">
            {/* Rank Projection */}
            {rankMovement && gwData && (
              <BroadcastCard title="Rank Projection" animationDelay={500}>
                <RankProjection
                  currentRank={rankMovement.current}
                  previousRank={rankMovement.previous ?? rankMovement.current}
                  currentPoints={processedSquad.totalPoints}
                  tierAvgPoints={samplesData?.samples?.top_10k?.avg_points ?? 0}
                  fixturesFinished={gwData.matchesPlayed}
                  fixturesTotal={gwData.totalMatches}
                />
              </BroadcastCard>
            )}

            {/* Variance Analysis */}
            {varianceData && (
              <BroadcastCard title="Luck Analysis" accentColor="purple" animationDelay={550}>
                <VarianceAnalysis
                  players={varianceData.players}
                  totalPredicted={varianceData.totalPredicted}
                  totalActual={varianceData.totalActual}
                />
              </BroadcastCard>
            )}

            {/* Fixture Impact Analysis */}
            <BroadcastCard title="Fixture Impact" animationDelay={600}>
              <FixtureThreatIndex
                fixtureData={gameweekData}
                fixtureImpacts={fixtureImpacts}
                selectedTier={comparisonTier}
                onTierChange={setComparisonTier}
              />
            </BroadcastCard>

            {/* Differential Analysis */}
            {differentialData.length > 0 && (
              <BroadcastCard title="Differentials" accentColor="purple" animationDelay={650}>
                <DifferentialAnalysis
                  players={differentialData}
                  tierLabel={tierLabels[comparisonTier]}
                />
              </BroadcastCard>
            )}
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
