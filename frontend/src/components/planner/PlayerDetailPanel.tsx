import { useState, useEffect, useRef, useCallback, memo } from 'react'
import type { PlayerMultiWeekPrediction, XMinsOverrides } from '../../api/client'
import { PositionBadge } from '../common/PositionBadge'

type SidebarTab = 'minutes' | 'transfer'

interface PlayerDetailPanelProps {
  player: {
    id: number
    web_name: string
    element_type: number
    team: number
    now_cost: number
  }
  teamName: string
  activeTab: SidebarTab
  onTabChange: (tab: SidebarTab) => void
  onClose: () => void
  // Minutes tab
  perGwXMins?: Record<number, number>
  gameweeks: number[]
  selectedGw: number | null
  xMinsOverrides: XMinsOverrides
  onXMinsChange: (playerId: number, xMins: number, gameweek?: number) => void
  baseExpectedMins: number
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
  perGwXMins,
  gameweeks,
  selectedGw,
  xMinsOverrides,
  onXMinsChange,
  baseExpectedMins,
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
    { id: 'minutes', label: 'xMins' },
    { id: 'transfer', label: 'Transfer' },
  ]

  return (
    <div>
      {/* Drawer header — player name, position badge, close button */}
      <div className="px-4 py-3 flex items-center justify-between bg-gradient-to-r from-fpl-green/30 via-fpl-green/10 to-transparent border-b border-fpl-green/30">
        <h3 className="font-display text-sm uppercase tracking-wider text-foreground">
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

      <div className="p-4">
        {/* Player info line */}
        <div className="flex items-center gap-2 mb-3">
          <span className="text-xs text-foreground-muted">{teamName}</span>
          <span className="text-xs text-foreground-dim">{'\u00B7'}</span>
          <span className="text-xs text-foreground-muted font-mono">
            {'\u00A3'}
            {(player.now_cost / 10).toFixed(1)}m
          </span>
        </div>

        {/* Tab switcher */}
        <div className="flex gap-1 mb-4">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => onTabChange(tab.id)}
              className={`flex-1 py-1.5 text-xs font-display uppercase tracking-wider rounded transition-colors ${
                activeTab === tab.id
                  ? 'bg-fpl-green/20 text-fpl-green border border-fpl-green/40'
                  : 'bg-surface-elevated text-foreground-muted hover:text-foreground hover:bg-surface-hover border border-transparent'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* xMins tab */}
        {activeTab === 'minutes' && (
          <div className="space-y-3 animate-fade-in-up">
            <div className="space-y-1">
              {gameweeks.map((gw) => {
                const backendMins = perGwXMins?.[gw]
                const displayMins = backendMins ?? baseExpectedMins
                const isCurrentGw = gw === selectedGw
                return (
                  <div
                    key={gw}
                    className={`flex items-center justify-between px-2 py-1.5 rounded transition-colors ${
                      isCurrentGw ? 'bg-fpl-green/10' : 'hover:bg-surface-hover/50'
                    }`}
                  >
                    <span
                      className={`text-xs font-display uppercase tracking-wider ${
                        isCurrentGw ? 'text-fpl-green' : 'text-foreground-muted'
                      }`}
                    >
                      GW{gw}
                    </span>
                    <GwXMinsInput
                      playerId={player.id}
                      gameweek={gw}
                      baseValue={displayMins}
                      committedValue={getGwOverride(gw)}
                      onChange={onXMinsChange}
                    />
                  </div>
                )
              })}
            </div>
          </div>
        )}

        {/* Transfer tab */}
        {activeTab === 'transfer' && (
          <div className="space-y-3 animate-fade-in-up">
            <div className="text-xs text-foreground-muted">
              Budget: {'\u00A3'}
              {budget.toFixed(1)}m
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
                    className="w-full flex items-center gap-2 p-2 rounded hover:bg-surface-hover text-left transition-colors animate-fade-in-up opacity-0"
                    style={{ animationDelay: `${idx * 20}ms` }}
                  >
                    <div className="flex-1 min-w-0">
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
