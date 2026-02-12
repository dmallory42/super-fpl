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
import { ComparisonBars, type PlayerImpact } from '../components/live/ComparisonBars'
import { FixtureScores } from '../components/live/FixtureScores'
import { CaptainBattle } from '../components/live/CaptainBattle'
import { RankProjection } from '../components/live/RankProjection'
import { VarianceAnalysis } from '../components/live/VarianceAnalysis'
import { FixtureThreatIndex } from '../components/live/FixtureThreatIndex'
import { DifferentialAnalysis } from '../components/live/DifferentialAnalysis'
import { GoodWeekBanner } from '../components/live/GoodWeekBanner'
import { formatRank } from '../lib/format'
import { type Tier, TIER_OPTIONS } from '../lib/tiers'
import { applyAutoSubs } from '../lib/autosubs'

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
  const query = params.toString()
  const newUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname
  window.history.replaceState({}, '', newUrl)
}

function formatUpdatedTime(value: string | undefined): string | null {
  if (!value) return null
  const timestamp = Date.parse(value)
  if (Number.isNaN(timestamp)) return null
  return new Date(timestamp).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

export function Live() {
  const initial = getInitialManagerId()
  const [managerId, setManagerId] = useState<number | null>(initial.id)
  const [managerInput, setManagerInput] = useState(initial.input)
  const [managerInputError, setManagerInputError] = useState<string | null>(null)
  const [comparisonTier, setComparisonTier] = useState<Tier>('top_10k')

  // Auto-detect current gameweek
  const { data: gwData, isLoading: isLoadingGw, gameweekData } = useCurrentGameweek()

  const gameweek = gwData?.gameweek ?? null
  const pollingContext = useMemo(
    () => ({
      isLive: gwData?.isLive,
      matchesInProgress: gwData?.matchesInProgress,
    }),
    [gwData?.isLive, gwData?.matchesInProgress]
  )

  const { data: playersData } = usePlayers()
  const {
    data: liveManager,
    isLoading: isLoadingManager,
    isFetching: isFetchingManager,
    error: managerError,
  } = useLiveManager(gameweek, managerId, pollingContext)
  const { data: samplesData, isFetching: isFetchingSamples } = useLiveSamples(
    gameweek,
    pollingContext
  )
  const { data: liveData, isFetching: isFetchingLiveData } = useLiveData(gameweek, pollingContext)
  const { data: bonusData, isFetching: isFetchingBonus } = useLiveBonus(gameweek, pollingContext)
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

  // Build a set of team IDs whose fixture has started (for filtering mid-GW analysis)
  const startedTeamIds = useMemo(() => {
    const ids = new Set<number>()
    if (!gameweekData?.fixtures) return ids
    for (const f of gameweekData.fixtures) {
      if (f.started) {
        ids.add(f.home_club_id)
        ids.add(f.away_club_id)
      }
    }
    return ids
  }, [gameweekData?.fixtures])

  // Calculate variance analysis data — only include players whose fixture has started
  const varianceData = useMemo(() => {
    if (!processedSquad || predictionsMap.size === 0) return null

    const startingXI = processedSquad.players.filter((p) => p.position <= 11)
    const players: Array<{ playerId: number; name: string; predicted: number; actual: number }> = []

    let totalPredicted = 0
    let totalActual = 0

    for (const player of startingXI) {
      const info = playersMap.get(player.player_id)

      // Skip players whose fixture hasn't started yet
      if (info?.team && !startedTeamIds.has(info.team)) continue

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

    if (players.length === 0) return null

    return {
      players,
      totalPredicted: Math.round(totalPredicted * 10) / 10,
      totalActual,
    }
  }, [processedSquad, predictionsMap, playersMap, startedTeamIds])

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
  const tierLabel = TIER_OPTIONS.find((t) => t.value === comparisonTier)?.label ?? comparisonTier
  const managerLastUpdated = formatUpdatedTime(liveManager?.updated_at)
  const isRefreshingLiveData =
    isFetchingManager || isFetchingSamples || isFetchingLiveData || isFetchingBonus
  const provisionalBonusIncluded = useMemo(() => {
    if (!processedSquad) return 0

    return processedSquad.players
      .filter((p) => p.position <= 11)
      .reduce((sum, p) => sum + (p.stats?.provisional_bonus ?? 0) * (p.multiplier ?? 1), 0)
  }, [processedSquad])

  const handleLoadManager = (id?: number) => {
    const rawInput = id !== undefined ? String(id) : managerInput.trim()
    if (!/^\d+$/.test(rawInput)) {
      setManagerInputError('Enter a valid numeric Manager ID.')
      return
    }

    const managerId = parseInt(rawInput, 10)
    if (!isNaN(managerId) && managerId > 0) {
      setManagerInputError(null)
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
    <div className="space-y-4 md:space-y-6">
      {/* Header */}
      <div className="animate-fade-in-up">
        <div className="flex items-center gap-3 mb-2">
          <h2 className="font-display text-2xl font-bold tracking-wider text-foreground">
            Live Gameweek
          </h2>
          {gwData?.isLive && <LiveIndicator size="lg" />}
          {managerId && (
            <button
              onClick={handleClearManager}
              className="ml-auto btn-secondary text-xs py-1 px-3"
            >
              Change Manager ID
            </button>
          )}
        </div>
        <p className="text-foreground-muted text-sm">
          {gwData ? (
            <>
              GW{gwData.gameweek} • {gwData.matchesPlayed}/{gwData.totalMatches} matches complete
              {gwData.matchesInProgress > 0 && ` • ${gwData.matchesInProgress} in progress`}
            </>
          ) : (
            'Track live points for your team during the gameweek.'
          )}
        </p>
        {managerId && managerLastUpdated && (
          <p className="text-xs text-foreground-dim mt-1">
            Updated {managerLastUpdated}
            {isRefreshingLiveData ? ' • Refreshing…' : ''}
          </p>
        )}
        {gwData && gwData.totalMatches > 0 && (
          <div
            className="flex h-1.5 mt-2 rounded-full overflow-hidden bg-surface-elevated max-w-xs"
            title={`${gwData.matchesPlayed} finished, ${gwData.matchesInProgress} live, ${gwData.totalMatches - gwData.matchesPlayed - gwData.matchesInProgress} upcoming`}
          >
            {gwData.matchesPlayed > 0 && (
              <div
                className="bg-foreground-muted/50"
                style={{ width: `${(gwData.matchesPlayed / gwData.totalMatches) * 100}%` }}
              />
            )}
            {gwData.matchesInProgress > 0 && (
              <div
                className="bg-fpl-green animate-pulse"
                style={{ width: `${(gwData.matchesInProgress / gwData.totalMatches) * 100}%` }}
              />
            )}
          </div>
        )}
      </div>

      {/* Manager Input - Collapsible when loaded */}
      {!managerId && (
        <div className="animate-fade-in-up animation-delay-100">
          <div className="flex gap-2 max-w-md">
            <input
              type="text"
              inputMode="numeric"
              pattern="[0-9]*"
              value={managerInput}
              onChange={(e) => {
                setManagerInput(e.target.value.replace(/\D/g, ''))
                setManagerInputError(null)
              }}
              placeholder="Enter your FPL Manager ID"
              className="input-broadcast flex-1"
              aria-label="Manager ID"
              onKeyDown={(e) => e.key === 'Enter' && handleLoadManager()}
            />
            <button onClick={() => handleLoadManager()} className="btn-primary">
              Track
            </button>
          </div>
          {managerInputError && (
            <p className="mt-2 text-xs text-destructive">{managerInputError}</p>
          )}
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
        <div className="space-y-4 md:space-y-6">
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
              subValue={
                provisionalBonusIncluded > 0
                  ? `Includes +${provisionalBonusIncluded} provisional bonus`
                  : undefined
              }
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

          {/* Good Week Celebration Banner */}
          {(() => {
            const top10k = comparisons.find((c) => c.tier === 'top_10k')
            return top10k ? (
              <GoodWeekBanner margin={top10k.difference} rankMovement={rankMovement?.movement} />
            ) : null
          })()}

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

          {/* Global tier context */}
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-3 p-2.5 sm:p-3 rounded-lg bg-surface-elevated/70">
            <span className="text-xs font-display uppercase tracking-wide text-foreground-muted">
              Compare Against
            </span>
            <div className="flex items-center gap-1 flex-wrap">
              {TIER_OPTIONS.map((tier) => (
                <button
                  key={tier.value}
                  onClick={() => setComparisonTier(tier.value)}
                  className={`px-2 py-0.5 text-xs font-display uppercase tracking-wide rounded transition-colors ${
                    comparisonTier === tier.value
                      ? 'bg-fpl-green/25 text-fpl-green ring-1 ring-fpl-green/30'
                      : 'text-foreground-dim hover:text-foreground hover:bg-surface'
                  }`}
                >
                  {tier.label}
                </button>
              ))}
            </div>
          </div>

          {/* Core cards */}
          <div className="grid md:grid-cols-2 gap-4 md:gap-6">
            {/* Gameweek Summary */}
            <BroadcastCard title="Gameweek Summary" animationDelay={300}>
              <ComparisonBars
                userPoints={processedSquad.totalPoints}
                comparisons={comparisons}
                playerImpacts={playerImpacts}
                selectedTier={comparisonTier}
                onTierChange={setComparisonTier}
                showTierSelector={false}
                animationDelay={350}
              />
              {rankMovement && gwData && (
                <div className="pt-3 mt-3 md:pt-4 md:mt-4 border-t border-border/40">
                  <RankProjection
                    currentRank={rankMovement.current}
                    previousRank={rankMovement.previous ?? rankMovement.current}
                    currentPoints={processedSquad.totalPoints}
                    tierAvgPoints={samplesData?.samples?.[comparisonTier]?.avg_points ?? 0}
                    fixturesFinished={gwData.matchesPlayed}
                    fixturesTotal={gwData.totalMatches}
                    compact
                  />
                </div>
              )}
            </BroadcastCard>

            {/* Key Swings */}
            <BroadcastCard title="Key Swings" accentColor="purple" animationDelay={350}>
              <CaptainBattle
                userCaptainId={userCaptain?.playerId}
                samples={samplesData?.samples}
                playersMap={playersMap}
                selectedTier={comparisonTier}
                onTierChange={setComparisonTier}
                showTierSelector={false}
              />
              {differentialData.length > 0 && (
                <div className="pt-3 mt-3 md:pt-4 md:mt-4 border-t border-border/40">
                  <DifferentialAnalysis
                    players={differentialData}
                    tierLabel={tierLabel}
                    showTierLabel={false}
                  />
                </div>
              )}
            </BroadcastCard>
          </div>

          {/* Match flow cards */}
          <div className="grid md:grid-cols-2 gap-4 md:gap-6">
            {/* Fixture Swing */}
            <BroadcastCard title="Fixture Swing" animationDelay={400}>
              <FixtureThreatIndex
                fixtureData={gameweekData}
                fixtureImpacts={fixtureImpacts}
                selectedTier={comparisonTier}
                onTierChange={setComparisonTier}
                showTierSelector={false}
                maxRows={5}
              />
            </BroadcastCard>

            {/* Fixtures */}
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

          {/* Advanced */}
          {varianceData && (
            <details className="rounded-lg border border-border/40 bg-surface-elevated/30">
              <summary className="cursor-pointer px-3 md:px-4 py-2.5 md:py-3 font-display text-xs md:text-sm uppercase tracking-wider text-foreground-muted">
                Variance
              </summary>
              <div className="px-3 md:px-4 pb-3 md:pb-4 pt-1">
                <VarianceAnalysis
                  players={varianceData.players}
                  totalPredicted={varianceData.totalPredicted}
                  totalActual={varianceData.totalActual}
                />
              </div>
            </details>
          )}
        </div>
      )}

      {/* Empty State */}
      {!managerId && !isLoadingGw && (
        <EmptyState
          icon={<CalendarIcon size={64} />}
          title="Track Live Points"
          description="Enter your FPL Manager ID to see live gameweek points, compare against top managers, and track bonus predictions."
        />
      )}
    </div>
  )
}
