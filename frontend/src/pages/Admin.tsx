import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { usePlayers } from '../hooks/usePlayers'
import {
  fetchPenaltyTakers,
  setPenaltyOrder,
  clearPenaltyOrder,
  setXMins,
  clearXMins,
} from '../api/client'
import { BroadcastCard } from '../components/ui/BroadcastCard'
import { GradientText } from '../components/ui/GradientText'
import type { Player } from '../types/player'
import { getPositionName } from '../types/player'

const POSITION_LABELS: Record<number, string> = { 1: 'GKP', 2: 'DEF', 3: 'MID', 4: 'FWD' }

export function Admin() {
  const { data: playersData, isLoading: playersLoading } = usePlayers()
  const { data: penaltyData } = useQuery({
    queryKey: ['penalty-takers'],
    queryFn: fetchPenaltyTakers,
  })
  const queryClient = useQueryClient()

  // Penalty takers section state
  const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null)

  // xMins section state
  const [xminsSearch, setXminsSearch] = useState('')
  const [addingPlayerId, setAddingPlayerId] = useState<number | null>(null)
  const [addingValue, setAddingValue] = useState('')

  const teams = useMemo(() => {
    if (!playersData?.teams) return []
    return [...playersData.teams].sort((a, b) => a.name.localeCompare(b.name))
  }, [playersData])

  const playersByTeam = useMemo(() => {
    if (!playersData?.players) return new Map<number, Player[]>()
    const map = new Map<number, Player[]>()
    for (const p of playersData.players) {
      if (p.element_type === 1) continue // skip goalkeepers for penalties
      const list = map.get(p.team) || []
      list.push(p)
      map.set(p.team, list)
    }
    // Sort each team's players by position then name
    for (const [teamId, players] of map) {
      map.set(
        teamId,
        players.sort(
          (a, b) => a.element_type - b.element_type || a.web_name.localeCompare(b.web_name)
        )
      )
    }
    return map
  }, [playersData])

  const penaltyByPlayer = useMemo(() => {
    if (!penaltyData?.penalty_takers) return new Map<number, number>()
    const map = new Map<number, number>()
    for (const t of penaltyData.penalty_takers) {
      map.set(t.id, t.penalty_order)
    }
    return map
  }, [penaltyData])

  // Count teams that have at least one penalty taker configured
  const configuredTeamCount = useMemo(() => {
    if (!penaltyData?.penalty_takers) return 0
    const teamSet = new Set(penaltyData.penalty_takers.map((t) => t.team))
    return teamSet.size
  }, [penaltyData])

  // xMins overrides from player data
  const playersWithOverrides = useMemo(() => {
    if (!playersData?.players) return []
    return playersData.players
      .filter((p) => p.xmins_override != null)
      .sort((a, b) => a.web_name.localeCompare(b.web_name))
  }, [playersData])

  // Search results for adding new xMins overrides
  const xminsSearchResults = useMemo(() => {
    if (!playersData?.players || xminsSearch.length < 2) return []
    const q = xminsSearch.toLowerCase()
    return playersData.players.filter((p) => p.web_name.toLowerCase().includes(q)).slice(0, 10)
  }, [playersData, xminsSearch])

  const teamMap = useMemo(() => {
    if (!playersData?.teams) return new Map<number, string>()
    return new Map(playersData.teams.map((t) => [t.id, t.short_name]))
  }, [playersData])

  // Mutations
  const penaltyMutation = useMutation({
    mutationFn: async ({ playerId, order }: { playerId: number; order: number | null }) => {
      if (order === null) {
        await clearPenaltyOrder(playerId)
      } else {
        await setPenaltyOrder(playerId, order)
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['penalty-takers'] })
      queryClient.invalidateQueries({ queryKey: ['players'] })
    },
  })

  const xminsMutation = useMutation({
    mutationFn: async ({ playerId, mins }: { playerId: number; mins: number | null }) => {
      if (mins === null) {
        await clearXMins(playerId)
      } else {
        await setXMins(playerId, mins)
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['players'] })
      setAddingPlayerId(null)
      setAddingValue('')
      setXminsSearch('')
    },
  })

  if (playersLoading) {
    return (
      <div className="space-y-6">
        <div className="h-8 w-48 bg-surface rounded animate-shimmer" />
        <div className="h-64 bg-surface rounded animate-shimmer" />
      </div>
    )
  }

  return (
    <div className="space-y-8">
      {/* Page header */}
      <div className="animate-fade-in-up">
        <h2 className="font-display text-2xl font-bold tracking-wider uppercase">
          <GradientText>Admin</GradientText>
        </h2>
        <p className="text-sm text-foreground-muted mt-1">
          Configure penalty takers and expected minutes overrides
        </p>
      </div>

      {/* Penalty Takers Section */}
      <BroadcastCard
        title="Penalty Takers"
        headerAction={
          <span className="font-mono text-xs text-foreground-muted">
            {configuredTeamCount}/20 teams
          </span>
        }
        animationDelay={100}
      >
        {/* Team selector */}
        <div className="mb-4">
          <select
            className="input-broadcast w-full sm:w-64"
            value={selectedTeamId ?? ''}
            onChange={(e) => setSelectedTeamId(e.target.value ? Number(e.target.value) : null)}
          >
            <option value="">Select a team...</option>
            {teams.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </div>

        {/* Player table for selected team */}
        {selectedTeamId && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left">
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted">
                    Player
                  </th>
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-16">
                    Pos
                  </th>
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-32">
                    Penalty Order
                  </th>
                </tr>
              </thead>
              <tbody>
                {(playersByTeam.get(selectedTeamId) || []).map((player) => {
                  const currentOrder = penaltyByPlayer.get(player.id) ?? null
                  return (
                    <tr
                      key={player.id}
                      className="border-b border-border/50 hover:bg-surface-hover transition-colors"
                    >
                      <td className="py-2 font-medium">{player.web_name}</td>
                      <td className="py-2 font-mono text-xs text-foreground-muted">
                        {POSITION_LABELS[player.element_type]}
                      </td>
                      <td className="py-2">
                        <select
                          className="input-broadcast text-sm py-1 px-2 w-24"
                          value={currentOrder ?? ''}
                          onChange={(e) => {
                            const val = e.target.value ? Number(e.target.value) : null
                            penaltyMutation.mutate({ playerId: player.id, order: val })
                          }}
                        >
                          <option value="">None</option>
                          <option value="1">1st</option>
                          <option value="2">2nd</option>
                          <option value="3">3rd</option>
                          <option value="4">4th</option>
                          <option value="5">5th</option>
                        </select>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        {!selectedTeamId && (
          <p className="text-sm text-foreground-dim">Select a team to configure penalty takers.</p>
        )}
      </BroadcastCard>

      {/* xMins Overrides Section */}
      <BroadcastCard
        title="Expected Minutes Overrides"
        headerAction={
          <span className="font-mono text-xs text-foreground-muted">
            {playersWithOverrides.length} active
          </span>
        }
        animationDelay={200}
      >
        {/* Search to add new override */}
        <div className="mb-4">
          <input
            type="text"
            className="input-broadcast w-full sm:w-64"
            placeholder="Search player to add override..."
            value={xminsSearch}
            onChange={(e) => {
              setXminsSearch(e.target.value)
              setAddingPlayerId(null)
            }}
          />

          {/* Search results dropdown */}
          {xminsSearchResults.length > 0 && !addingPlayerId && (
            <div className="mt-1 border border-border rounded-lg bg-surface overflow-hidden">
              {xminsSearchResults.map((p) => (
                <button
                  key={p.id}
                  className="w-full text-left px-3 py-2 hover:bg-surface-hover transition-colors text-sm flex items-center gap-2"
                  onClick={() => {
                    setAddingPlayerId(p.id)
                    setXminsSearch(p.web_name)
                    setAddingValue(
                      String(
                        p.xmins_override ??
                          (p.minutes && p.appearances ? Math.round(p.minutes / p.appearances) : 90)
                      )
                    )
                  }}
                >
                  <span className="font-medium">{p.web_name}</span>
                  <span className="font-mono text-xs text-foreground-muted">
                    {teamMap.get(p.team)} &middot; {getPositionName(p.element_type)}
                  </span>
                </button>
              ))}
            </div>
          )}

          {/* Add form */}
          {addingPlayerId && (
            <div className="mt-2 flex items-center gap-2">
              <input
                type="number"
                className="input-broadcast w-20 text-sm py-1 px-2"
                min={0}
                max={95}
                value={addingValue}
                onChange={(e) => setAddingValue(e.target.value)}
                placeholder="mins"
              />
              <button
                className="btn-primary text-sm py-1 px-3"
                onClick={() => {
                  const mins = Number(addingValue)
                  if (mins >= 0 && mins <= 95) {
                    xminsMutation.mutate({ playerId: addingPlayerId, mins })
                  }
                }}
              >
                Set
              </button>
              <button
                className="btn-secondary text-sm py-1 px-3"
                onClick={() => {
                  setAddingPlayerId(null)
                  setXminsSearch('')
                  setAddingValue('')
                }}
              >
                Cancel
              </button>
            </div>
          )}
        </div>

        {/* Active overrides table */}
        {playersWithOverrides.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left">
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted">
                    Player
                  </th>
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-16">
                    Team
                  </th>
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-24">
                    Avg Mins
                  </th>
                  <th className="pb-2 font-display text-xs uppercase tracking-wider text-foreground-muted w-28">
                    Override
                  </th>
                  <th className="pb-2 w-16"></th>
                </tr>
              </thead>
              <tbody>
                {playersWithOverrides.map((player) => {
                  const avgMins =
                    player.appearances && player.appearances > 0
                      ? Math.round(player.minutes / player.appearances)
                      : '-'
                  return (
                    <tr
                      key={player.id}
                      className="border-b border-border/50 hover:bg-surface-hover transition-colors"
                    >
                      <td className="py-2 font-medium">{player.web_name}</td>
                      <td className="py-2 font-mono text-xs text-foreground-muted">
                        {teamMap.get(player.team)}
                      </td>
                      <td className="py-2 font-mono text-xs text-foreground-muted">{avgMins}</td>
                      <td className="py-2">
                        <input
                          type="number"
                          className="input-broadcast w-20 text-sm py-1 px-2"
                          min={0}
                          max={95}
                          defaultValue={player.xmins_override ?? undefined}
                          onBlur={(e) => {
                            const newVal = Number(e.target.value)
                            if (newVal !== player.xmins_override && newVal >= 0 && newVal <= 95) {
                              xminsMutation.mutate({ playerId: player.id, mins: newVal })
                            }
                          }}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
                          }}
                        />
                      </td>
                      <td className="py-2">
                        <button
                          className="text-xs text-highlight hover:text-highlight/80 transition-colors"
                          onClick={() => xminsMutation.mutate({ playerId: player.id, mins: null })}
                        >
                          Clear
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-foreground-dim">
            No xMins overrides set. Search above to add one.
          </p>
        )}
      </BroadcastCard>
    </div>
  )
}
