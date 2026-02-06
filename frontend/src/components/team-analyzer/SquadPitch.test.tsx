import { describe, it, expect } from 'vitest'
import { render, screen } from '../../test/utils'
import { SquadPitch } from './SquadPitch'
import type { Pick, Player } from '../../types'

const createPlayer = (overrides: Partial<Player> = {}): Player => ({
  id: 1,
  first_name: 'Mohamed',
  second_name: 'Salah',
  web_name: 'Salah',
  team: 10,
  team_code: 10,
  element_type: 3,
  now_cost: 130,
  total_points: 150,
  points_per_game: '7.5',
  form: '8.2',
  selected_by_percent: '45.0',
  minutes: 1800,
  goals_scored: 12,
  assists: 8,
  clean_sheets: 5,
  goals_conceded: 0,
  yellow_cards: 1,
  red_cards: 0,
  saves: 0,
  bonus: 15,
  bps: 450,
  influence: '600.0',
  creativity: '400.0',
  threat: '800.0',
  ict_index: '180.0',
  status: 'a',
  news: '',
  news_added: null,
  chance_of_playing_next_round: 100,
  chance_of_playing_this_round: 100,
  ...overrides,
})

const createPick = (overrides: Partial<Pick> = {}): Pick => ({
  element: 1,
  position: 1,
  multiplier: 1,
  is_captain: false,
  is_vice_captain: false,
  ...overrides,
})

describe('SquadPitch', () => {
  const teamsMap = new Map([
    [1, 'ARS'],
    [10, 'LIV'],
    [2, 'AVL'],
  ])

  describe('player rendering', () => {
    it('renders all players with team shirts (not position-based colored dots)', () => {
      const goalkeeper = createPlayer({ id: 1, element_type: 1, web_name: 'Raya' })
      const defender = createPlayer({ id: 2, element_type: 2, web_name: 'Saliba' })
      const midfielder = createPlayer({ id: 3, element_type: 3, web_name: 'Saka' })
      const forward = createPlayer({ id: 4, element_type: 4, web_name: 'Haaland' })

      const playersMap = new Map([
        [1, goalkeeper],
        [2, defender],
        [3, midfielder],
        [4, forward],
      ])

      const picks: Pick[] = [
        createPick({ element: 1, position: 1 }),
        createPick({ element: 2, position: 2 }),
        createPick({ element: 3, position: 6 }),
        createPick({ element: 4, position: 10 }),
      ]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      // All player names should be rendered
      expect(screen.getByText('Raya')).toBeInTheDocument()
      expect(screen.getByText('Saliba')).toBeInTheDocument()
      expect(screen.getByText('Saka')).toBeInTheDocument()
      expect(screen.getByText('Haaland')).toBeInTheDocument()

      // Should NOT have position-based colors
    })
  })

  describe('player info content', () => {
    it('does not show points/numbers overlaid on the player shirt', () => {
      const player = createPlayer({ id: 1, total_points: 150, web_name: 'Salah' })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1 })]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      // The player name should be rendered but total_points should not appear as text
      expect(screen.getByText('Salah')).toBeInTheDocument()
      expect(screen.queryByText('150')).not.toBeInTheDocument()
    })
  })

  describe('player info display', () => {
    it('shows player name below the dot', () => {
      const player = createPlayer({ id: 1, web_name: 'Salah', team: 10 })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1 })]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      expect(screen.getByText('Salah')).toBeInTheDocument()
    })

    it('shows team name below the player name', () => {
      const player = createPlayer({ id: 1, web_name: 'Salah', team: 10 })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1 })]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      expect(screen.getByText('LIV')).toBeInTheDocument()
    })

    it('shows price below team name', () => {
      const player = createPlayer({ id: 1, web_name: 'Salah', team: 10, now_cost: 130 })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1 })]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      expect(screen.getByText('£13.0m')).toBeInTheDocument()
    })
  })

  describe('captain and vice captain badges', () => {
    it('shows captain badge', () => {
      const player = createPlayer({ id: 1, web_name: 'Haaland' })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1, is_captain: true, multiplier: 2 })]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      expect(screen.getByText('C')).toBeInTheDocument()
    })

    it('shows vice captain badge', () => {
      const player = createPlayer({ id: 1, web_name: 'Salah' })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1, is_vice_captain: true })]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      expect(screen.getByText('V')).toBeInTheDocument()
    })
  })

  describe('bench display', () => {
    it('shows bench players separated from starting XI', () => {
      const starter = createPlayer({ id: 1, web_name: 'Salah', element_type: 3 })
      const benchPlayer = createPlayer({ id: 2, web_name: 'BenchGuy', element_type: 3 })
      const playersMap = new Map([
        [1, starter],
        [2, benchPlayer],
      ])
      const picks = [
        createPick({ element: 1, position: 1 }),
        createPick({ element: 2, position: 12 }), // bench position
      ]

      render(<SquadPitch picks={picks} players={playersMap} teams={teamsMap} />)

      expect(screen.getByText('Bench')).toBeInTheDocument()
      expect(screen.getByText('BenchGuy')).toBeInTheDocument()
    })
  })
})
