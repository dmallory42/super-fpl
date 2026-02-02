import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { FormationPitch } from './FormationPitch'

const mockTeams = {
  1: { id: 1, short_name: 'ARS' },
  2: { id: 2, short_name: 'CHE' },
  10: { id: 10, short_name: 'LIV' },
}

const createPlayer = (overrides = {}) => ({
  player_id: 1,
  web_name: 'Salah',
  element_type: 3,
  team: 10,
  multiplier: 1,
  is_captain: false,
  is_vice_captain: false,
  position: 1,
  points: 8,
  effective_points: 8,
  ...overrides,
})

describe('FormationPitch', () => {
  it('renders starting XI players', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'Raya', element_type: 1, team: 1, position: 1 }),
      createPlayer({ player_id: 2, web_name: 'Saliba', element_type: 2, team: 1, position: 2 }),
      createPlayer({ player_id: 3, web_name: 'Gabriel', element_type: 2, team: 1, position: 3 }),
      createPlayer({ player_id: 4, web_name: 'Trippier', element_type: 2, team: 1, position: 4 }),
      createPlayer({ player_id: 5, web_name: 'White', element_type: 2, team: 1, position: 5 }),
      createPlayer({ player_id: 6, web_name: 'Saka', element_type: 3, team: 1, position: 6 }),
      createPlayer({ player_id: 7, web_name: 'Palmer', element_type: 3, team: 2, position: 7 }),
      createPlayer({ player_id: 8, web_name: 'Rice', element_type: 3, team: 1, position: 8 }),
      createPlayer({ player_id: 9, web_name: 'Salah', element_type: 3, team: 10, position: 9 }),
      createPlayer({ player_id: 10, web_name: 'Haaland', element_type: 4, team: 1, position: 10, multiplier: 2, is_captain: true }),
      createPlayer({ player_id: 11, web_name: 'Watkins', element_type: 4, team: 1, position: 11 }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('Salah')).toBeInTheDocument()
    expect(screen.getByText('Haaland')).toBeInTheDocument()
    expect(screen.getByText('Raya')).toBeInTheDocument()
  })

  it('renders bench players', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'Raya', element_type: 1, position: 1 }),
      createPlayer({ player_id: 12, web_name: 'BenchPlayer', element_type: 2, position: 12 }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('Bench')).toBeInTheDocument()
    expect(screen.getByText('BenchPlayer')).toBeInTheDocument()
  })

  it('shows captain badge for captains', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'Captain', position: 1, multiplier: 2, is_captain: true }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('C')).toBeInTheDocument()
  })

  it('shows vice captain badge for vice captains', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'ViceCaptain', position: 1, is_vice_captain: true }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('V')).toBeInTheDocument()
  })

  it('shows team short name', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'Salah', team: 10, position: 1 }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('LIV')).toBeInTheDocument()
  })

  it('shows effective points', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'Player', position: 1, points: 8, effective_points: 16, multiplier: 2 }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('16')).toBeInTheDocument()
  })

  it('falls back to ??? for unknown team', () => {
    const players = [
      createPlayer({ player_id: 1, web_name: 'Player', team: 999, position: 1 }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('???')).toBeInTheDocument()
  })

  it('shows player ID when web_name is missing', () => {
    const players = [
      createPlayer({ player_id: 123, web_name: undefined, position: 1 }),
    ]

    render(<FormationPitch players={players} teams={mockTeams} />)

    expect(screen.getByText('P123')).toBeInTheDocument()
  })

  describe('effective ownership display', () => {
    it('hides EO by default', () => {
      const players = [
        createPlayer({
          player_id: 1,
          web_name: 'Player',
          position: 1,
          effective_ownership: {
            ownership_percent: 50,
            captain_percent: 10,
            effective_ownership: 60,
            points_swing: 5,
          },
        }),
      ]

      render(<FormationPitch players={players} teams={mockTeams} />)

      expect(screen.queryByText(/EO:/)).not.toBeInTheDocument()
    })

    it('shows EO when showEffectiveOwnership is true', () => {
      const players = [
        createPlayer({
          player_id: 1,
          web_name: 'Player',
          position: 1,
          effective_ownership: {
            ownership_percent: 50,
            captain_percent: 10,
            effective_ownership: 60,
            points_swing: 5,
          },
        }),
      ]

      render(<FormationPitch players={players} teams={mockTeams} showEffectiveOwnership />)

      expect(screen.getByText('EO: 60%')).toBeInTheDocument()
    })
  })
})
