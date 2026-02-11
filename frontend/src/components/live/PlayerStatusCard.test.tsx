import { describe, it, expect } from 'vitest'
import { render, screen, fireEvent } from '../../test/utils'
import { PlayerStatusCard } from './PlayerStatusCard'

describe('PlayerStatusCard', () => {
  it('exposes status in accessible label', () => {
    render(
      <PlayerStatusCard
        playerId={1}
        webName="Salah"
        teamName="LIV"
        teamId={1}
        points={10}
        multiplier={2}
        isCaptain
        isViceCaptain={false}
        status="playing"
        matchMinute={72}
      />
    )

    expect(screen.getByLabelText("Salah (LIV), 20 points, Live 72'")).toBeInTheDocument()
    expect(screen.getByText("Live 72'")).toBeInTheDocument()
  })

  it('shows minute tooltip on focus for live players', () => {
    render(
      <PlayerStatusCard
        playerId={2}
        webName="Haaland"
        teamName="MCI"
        teamId={2}
        points={6}
        multiplier={1}
        isCaptain={false}
        isViceCaptain={false}
        status="playing"
        matchMinute={55}
      />
    )

    const card = screen.getByLabelText("Haaland (MCI), 6 points, Live 55'")
    fireEvent.focus(card)

    expect(screen.getByText("55' played")).toBeInTheDocument()
  })
})
