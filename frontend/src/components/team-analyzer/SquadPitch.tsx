import { useMemo } from 'react'
import type { Pick, Player } from '../../types'
import { formatPrice } from '../../types'
import { PitchPlayerCard } from '../pitch/PitchPlayerCard'
import { PitchLayout } from '../pitch/PitchLayout'

interface SquadPitchProps {
  picks: Pick[]
  players: Map<number, Player>
  teams: Map<number, string>
  playerPoints?: Record<number, number>
}

interface PlayerSlotData {
  pick: Pick
  player: Player
  teamName: string
  contributedPoints?: number
}

function buildPlayerSlot(
  pick: Pick,
  player: Player | undefined,
  teamName: string,
  contributedPoints?: number
): PlayerSlotData | null {
  if (!player) {
    return null
  }

  return { pick, player, teamName, contributedPoints }
}

export function SquadPitch({ picks, players, teams, playerPoints }: SquadPitchProps) {
  const { startingXI, bench } = useMemo(() => {
    const starting = picks.filter((p) => p.position <= 11).sort((a, b) => a.position - b.position)
    const benchPlayers = picks
      .filter((p) => p.position > 11)
      .sort((a, b) => a.position - b.position)
    return { startingXI: starting, bench: benchPlayers }
  }, [picks])

  const rows = useMemo(() => {
    const gk: PlayerSlotData[] = []
    const def: PlayerSlotData[] = []
    const mid: PlayerSlotData[] = []
    const fwd: PlayerSlotData[] = []

    for (const pick of startingXI) {
      const player = players.get(pick.element)
      const teamName = player ? teams.get(player.team) || '' : ''
      const slot = buildPlayerSlot(pick, player, teamName, playerPoints?.[pick.element])
      if (!slot) continue

      switch (slot.player.element_type) {
        case 1:
          gk.push(slot)
          break
        case 2:
          def.push(slot)
          break
        case 3:
          mid.push(slot)
          break
        case 4:
          fwd.push(slot)
          break
      }
    }

    // Order: GK at top, then DEF, MID, FWD at bottom
    return [gk, def, mid, fwd]
  }, [startingXI, players, teams, playerPoints])

  const benchRows = useMemo(
    () =>
      bench
        .map((pick) => {
          const player = players.get(pick.element)
          const teamName = player ? teams.get(player.team) || '' : ''
          return buildPlayerSlot(pick, player, teamName, playerPoints?.[pick.element])
        })
        .filter((row): row is PlayerSlotData => row !== null),
    [bench, players, teams, playerPoints]
  )

  const rowElements = rows.reduce<{ rows: JSX.Element[][]; offset: number }>(
    (acc, row) => {
      acc.rows.push(
        row.map((pick, itemIdx) => (
          <PitchPlayerCard
            key={pick.pick.element}
            teamId={pick.player.team ?? 0}
            name={pick.player.web_name}
            secondaryText={pick.teamName}
            metaText={`£${formatPrice(pick.player.now_cost)}m`}
            pointsText={
              pick.contributedPoints !== undefined
                ? String(Math.round(pick.contributedPoints))
                : undefined
            }
            isCaptain={pick.pick.is_captain}
            isViceCaptain={pick.pick.is_vice_captain}
            pulseShirt={pick.pick.is_captain}
            animationDelay={(acc.offset + itemIdx) * 50}
          />
        ))
      )
      acc.offset += row.length
      return acc
    },
    { rows: [], offset: 0 }
  )

  const benchElements = benchRows.map((pick, idx) => (
    <PitchPlayerCard
      key={pick.pick.element}
      teamId={pick.player.team ?? 0}
      name={pick.player.web_name}
      secondaryText={pick.teamName}
      metaText={`£${formatPrice(pick.player.now_cost)}m`}
      pointsText={
        pick.contributedPoints !== undefined
          ? String(Math.round(pick.contributedPoints))
          : undefined
      }
      isCaptain={pick.pick.is_captain}
      isViceCaptain={pick.pick.is_vice_captain}
      pulseShirt={pick.pick.is_captain}
      isBench
      animationDelay={(rowElements.offset + idx) * 50}
    />
  ))

  return <PitchLayout rows={rowElements.rows} bench={benchElements} variant="squad" />
}
