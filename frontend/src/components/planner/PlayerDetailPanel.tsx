import { useState, useEffect, useRef, useCallback, memo } from 'react'
import type { PlayerMultiWeekPrediction, XMinsOverrides, FixtureOpponent } from '../../api/client'
import { PositionBadge } from '../common/PositionBadge'
import { GradientText } from '../ui/GradientText'
import { scalePoints } from '../../lib/predictions'

type SidebarTab = 'projections' | 'transfer'

interface PlayerDetailPanelProps {
  player: {
    id: number
    web_name: string
    element_type: number
    team: number
    now_cost: number
    total_points: number
    form: string
    selected_by_percent: string
    goals_scored: number
    assists: number
    clean_sheets: number
    minutes: number
    saves: number
    starts?: number
    appearances?: number
    status: string
    news: string
  }
  teamName: string
  activeTab: SidebarTab
  onTabChange: (tab: SidebarTab) => void
  onClose: () => void
  // Projections tab
  playerPrediction?: PlayerMultiWeekPrediction
  perGwXMins?: Record<number, number>
  gameweeks: number[]
  selectedGw: number | null
  xMinsOverrides: XMinsOverrides
  onXMinsChange: (playerId: number, xMins: number, gameweek?: number) => void
  baseExpectedMins: number
  fixtures?: Record<number, FixtureOpponent[]>
  // Transfer tab
  budget: number
  replacementSearch: string
  onReplacementSearchChange: (search: string) => void
  availableReplacements: PlayerMultiWeekPrediction[]
  currentPlayerPredicted: number
  teamsMap: Map<number, string>
  onSelectReplacement: (player: PlayerMultiWeekPrediction) => void
}

/** Self-contained xMins input for per-GW override in the sidebar. */
const GwXMinsInput = memo(function GwXMinsInput({
  playerId,
  gameweek,
  baseValue,
  committedValue,
  onChange,
}: {
  playerId: number
  gameweek: number
  baseValue: number
  committedValue: number | undefined
  onChange: (playerId: number, xMins: number, gameweek: number) => void
}) {
  const [localValue, setLocalValue] = useState(committedValue != null ? String(committedValue) : '')
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    setLocalValue(committedValue != null ? String(committedValue) : '')
  }, [committedValue])

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const val = e.target.value
      setLocalValue(val)

      if (timerRef.current) clearTimeout(timerRef.current)

      timerRef.current = setTimeout(() => {
        if (val === '') return
        const num = Math.max(0, Math.min(95, parseInt(val, 10)))
        if (!isNaN(num)) {
          onChange(playerId, num, gameweek)
        }
      }, 600)
    },
    [onChange, playerId, gameweek]
  )

  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [])

  const hasOverride = localValue !== ''

  return (
    <input
      type="text"
      inputMode="numeric"
      pattern="[0-9]*"
      value={localValue}
      placeholder={String(baseValue)}
      onChange={handleChange}
      className={`w-14 px-1 py-0.5 text-center text-xs font-mono rounded border bg-surface-elevated transition-colors
        ${hasOverride ? 'border-fpl-green/60 text-fpl-green' : 'border-border/30 text-foreground-muted'}
      `}
    />
  )
})

export function PlayerDetailPanel({
  player,
  teamName,
  activeTab,
  onTabChange,
  onClose,
  playerPrediction,
  perGwXMins,
  gameweeks,
  selectedGw,
  xMinsOverrides,
  onXMinsChange,
  baseExpectedMins,
  fixtures,
  budget,
  replacementSearch,
  onReplacementSearchChange,
  availableReplacements,
  currentPlayerPredicted,
  teamsMap,
  onSelectReplacement,
}: PlayerDetailPanelProps) {
  // Resolve per-GW committed values from overrides
  const getGwOverride = (gw: number): number | undefined => {
    const raw = xMinsOverrides[player.id]
    if (typeof raw === 'object' && raw !== null) return raw[gw]
    return undefined
  }

  const tabs: { id: SidebarTab; label: string }[] = [
    { id: 'projections', label: 'Projections' },
    { id: 'transfer', label: 'Transfer' },
  ]

  const formatGwFixture = (gw: number): string => {
    const gwFixtures = fixtures?.[gw]
    if (!gwFixtures?.length) return '-'
    return gwFixtures.map((f) => (f.is_home ? f.opponent : f.opponent.toLowerCase())).join(', ')
  }

  const isGkp = player.element_type === 1
  const isDef = player.element_type === 2

  // Compute scaled xPts per-GW using unified if-fit formula
  const scaledXPts = (gw: number): number | null => {
    // No fixture in this GW = blank gameweek, no points
    if (!fixtures?.[gw]?.length) return 0
    const ifFitPts = playerPrediction?.if_fit_predictions?.[gw] ?? playerPrediction?.predictions[gw]
    if (ifFitPts == null) return null
    const ifFitMins = playerPrediction?.expected_mins_if_fit ?? baseExpectedMins
    const override = getGwOverride(gw)
    const effectiveMins = override ?? perGwXMins?.[gw] ?? playerPrediction?.expected_mins ?? 0
    return scalePoints(ifFitPts, ifFitMins, effectiveMins)
  }

  // Compute total xMins and total scaled xPts across gameweeks
  const totalXMins = gameweeks.reduce((sum, gw) => {
    const override = getGwOverride(gw)
    if (override != null) return sum + override
    return sum + (perGwXMins?.[gw] ?? baseExpectedMins)
  }, 0)

  const totalScaledXPts = gameweeks.reduce((sum, gw) => {
    const pts = scaledXPts(gw)
    return sum + (pts ?? 0)
  }, 0)

  return (
    <div>
      {/* Hero Header */}
      <div className="relative border-b border-fpl-green/20">
        {/* Left accent bar */}
        <div className="absolute left-0 top-0 bottom-0 w-[3px] bg-fpl-green" />

        {/* Background gradient */}
        <div className="bg-gradient-to-r from-fpl-green/20 via-fpl-green/5 to-transparent">
          {/* Top row: name, position, close */}
          <div className="px-4 pt-3 pb-1 flex items-center justify-between">
            <h3 className="font-display text-lg uppercase tracking-widest text-foreground font-bold">
              {player.web_name}
            </h3>
            <div className="flex items-center gap-2">
              <PositionBadge elementType={player.element_type} />
              <button
                onClick={onClose}
                className="w-7 h-7 flex items-center justify-center rounded text-foreground-muted hover:text-foreground hover:bg-surface-hover transition-colors"
              >
                {'\u2715'}
              </button>
            </div>
          </div>

          {/* Team + price line */}
          <div className="px-4 pb-2 flex items-center gap-2">
            <span className="text-xs text-foreground-muted">{teamName}</span>
            <span className="text-xs text-foreground-dim">{'\u00B7'}</span>
            <span className="text-xs text-foreground-muted font-mono">
              {'\u00A3'}
              {(player.now_cost / 10).toFixed(1)}m
            </span>
            {player.news && (
              <>
                <span className="text-xs text-foreground-dim">{'\u00B7'}</span>
                <span
                  className={`text-xs ${player.status === 'a' ? 'text-foreground-dim' : 'text-amber-400'}`}
                >
                  {player.news}
                </span>
              </>
            )}
          </div>

          {/* Mini stat panels */}
          <div className="px-4 pb-3 grid grid-cols-4 gap-2">
            {[
              { value: parseFloat(player.form).toFixed(1), label: 'FORM' },
              { value: player.total_points, label: 'PTS' },
              {
                value: playerPrediction ? totalScaledXPts.toFixed(1) : '-',
                label: 'xPTS',
                gradient: true,
              },
              { value: `${parseFloat(player.selected_by_percent).toFixed(0)}%`, label: 'OWN' },
            ].map((stat, i) => (
              <div
                key={stat.label}
                className="bg-surface/60 rounded px-2 py-1.5 animate-fade-in-up opacity-0"
                style={{ animationDelay: `${100 + i * 50}ms` }}
              >
                <div
                  className={`font-mono text-base font-bold ${stat.gradient ? '' : 'text-foreground'}`}
                >
                  {stat.gradient ? <GradientText>{stat.value}</GradientText> : stat.value}
                </div>
                <div className="font-display text-[10px] uppercase tracking-wider text-foreground-muted">
                  {stat.label}
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Season stats strip */}
      <div className="px-4 py-2 border-b border-border/30 animate-fade-in-up opacity-0 [animation-delay:250ms]">
        <div className="flex items-center gap-1.5 text-xs font-mono text-foreground-dim flex-wrap">
          {isGkp ? (
            <>
              <span>
                SV <span className="text-foreground-muted">{player.saves}</span>
              </span>
              <span>{'\u00B7'}</span>
              <span>
                CS <span className="text-foreground-muted">{player.clean_sheets}</span>
              </span>
            </>
          ) : (
            <>
              <span>
                G <span className="text-foreground-muted">{player.goals_scored}</span>
              </span>
              <span>{'\u00B7'}</span>
              <span>
                A <span className="text-foreground-muted">{player.assists}</span>
              </span>
              {isDef && (
                <>
                  <span>{'\u00B7'}</span>
                  <span>
                    CS <span className="text-foreground-muted">{player.clean_sheets}</span>
                  </span>
                </>
              )}
            </>
          )}
          <span>{'\u00B7'}</span>
          <span>
            Starts{' '}
            <span className="text-foreground-muted">
              {player.starts ?? '-'}/{player.appearances ?? '-'}
            </span>
          </span>
          <span>{'\u00B7'}</span>
          <span>
            Mins <span className="text-foreground-muted">{player.minutes.toLocaleString()}</span>
          </span>
        </div>
      </div>

      <div className="p-4">
        {/* Tab switcher */}
        <div className="flex gap-0 mb-4 border-b border-border/50">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => onTabChange(tab.id)}
              className={`flex-1 py-2 text-xs font-display uppercase tracking-wider transition-colors ${
                activeTab === tab.id
                  ? 'text-fpl-green border-b-2 border-fpl-green'
                  : 'text-foreground-muted hover:text-foreground'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Projections tab */}
        {activeTab === 'projections' && (
          <div className="animate-fade-in-up">
            {/* Column headers */}
            <div className="flex items-center px-2 pb-2 border-b border-border/30">
              <span className="w-12 font-display text-[10px] uppercase tracking-widest text-foreground-dim">
                GW
              </span>
              <span className="w-16 font-display text-[10px] uppercase tracking-widest text-foreground-dim text-center">
                Fix
              </span>
              <span className="flex-1 font-display text-[10px] uppercase tracking-widest text-foreground-dim text-right pr-3">
                xMins
              </span>
              <span className="w-14 font-display text-[10px] uppercase tracking-widest text-foreground-dim text-right">
                xPts
              </span>
            </div>

            {/* GW rows */}
            <div className="divide-y divide-border/10">
              {gameweeks.map((gw, idx) => {
                const backendMins = perGwXMins?.[gw]
                const displayMins = backendMins ?? baseExpectedMins
                const isCurrentGw = gw === selectedGw
                const xPts = scaledXPts(gw)

                return (
                  <div
                    key={gw}
                    className={`relative flex items-center px-2 py-1.5 transition-colors animate-fade-in-up opacity-0 ${
                      isCurrentGw ? 'bg-fpl-green/10' : 'hover:bg-surface-hover/50'
                    }`}
                    style={{ animationDelay: `${idx * 30}ms` }}
                  >
                    {isCurrentGw && (
                      <div className="absolute left-0 top-0 bottom-0 w-[3px] bg-fpl-green rounded-r" />
                    )}
                    <span
                      className={`w-12 text-xs font-display uppercase tracking-wider ${
                        isCurrentGw ? 'text-fpl-green' : 'text-foreground-muted'
                      }`}
                    >
                      GW{gw}
                    </span>
                    <span className="w-16 text-center text-xs font-mono text-foreground-muted">
                      {formatGwFixture(gw)}
                    </span>
                    <div className="flex-1 flex justify-end pr-3">
                      <GwXMinsInput
                        playerId={player.id}
                        gameweek={gw}
                        baseValue={displayMins}
                        committedValue={getGwOverride(gw)}
                        onChange={onXMinsChange}
                      />
                    </div>
                    <span
                      className={`w-14 text-right text-sm font-mono ${
                        xPts == null
                          ? 'text-foreground-dim'
                          : xPts >= 6
                            ? 'text-fpl-green font-semibold'
                            : xPts >= 4
                              ? 'text-foreground'
                              : 'text-foreground-muted'
                      }`}
                    >
                      {xPts != null ? xPts.toFixed(1) : '-'}
                    </span>
                  </div>
                )
              })}
            </div>

            {/* Summary row */}
            <div
              className="flex items-center px-2 py-2 mt-1 border-t border-border/40 animate-fade-in-up opacity-0"
              style={{ animationDelay: `${gameweeks.length * 30 + 30}ms` }}
            >
              <span className="w-12 text-xs font-display uppercase tracking-wider text-foreground-muted">
                Total
              </span>
              <span className="w-16" />
              <span className="flex-1 text-right pr-3 text-sm font-mono font-bold text-foreground-muted">
                {totalXMins}
              </span>
              <span className="w-14 text-right text-sm font-mono font-bold">
                <GradientText>{playerPrediction ? totalScaledXPts.toFixed(1) : '-'}</GradientText>
              </span>
            </div>
          </div>
        )}

        {/* Transfer tab */}
        {activeTab === 'transfer' && (
          <div className="space-y-3 animate-fade-in-up">
            <div className="text-xs text-foreground-muted">
              Budget:{' '}
              <span className="font-mono">
                {'\u00A3'}
                {budget.toFixed(1)}m
              </span>
            </div>
            <input
              type="text"
              value={replacementSearch}
              onChange={(e) => onReplacementSearchChange(e.target.value)}
              placeholder="Search players..."
              className="input-broadcast"
              autoFocus
            />
            <div className="max-h-[400px] overflow-y-auto space-y-1">
              {availableReplacements.map((rPlayer, idx) => {
                const gain = rPlayer.total_predicted - currentPlayerPredicted

                return (
                  <button
                    key={rPlayer.player_id}
                    onClick={() => onSelectReplacement(rPlayer)}
                    className="group w-full relative flex items-center gap-2 p-2 rounded hover:bg-surface-hover text-left transition-colors animate-fade-in-up opacity-0"
                    style={{ animationDelay: `${idx * 20}ms` }}
                  >
                    <div className="absolute left-0 top-1 bottom-1 w-[2px] rounded bg-transparent group-hover:bg-fpl-green/40 transition-colors" />
                    <div className="flex-1 min-w-0 pl-1">
                      <div className="text-foreground font-medium text-sm truncate">
                        {rPlayer.web_name}
                      </div>
                      <div className="text-xs text-foreground-dim">
                        {teamsMap.get(rPlayer.team)} {'\u00B7'} {'\u00A3'}
                        {(rPlayer.now_cost / 10).toFixed(1)}m
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-fpl-green font-mono text-sm font-bold">
                        {rPlayer.total_predicted.toFixed(1)}
                      </div>
                      <div
                        className={`text-xs font-mono ${gain > 0 ? 'text-fpl-green' : gain < 0 ? 'text-destructive' : 'text-foreground-muted'}`}
                      >
                        {gain > 0 ? '+' : ''}
                        {gain.toFixed(1)}
                      </div>
                    </div>
                  </button>
                )
              })}
              {availableReplacements.length === 0 && (
                <div className="text-center text-foreground-muted py-4 text-sm">
                  No replacements found
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
