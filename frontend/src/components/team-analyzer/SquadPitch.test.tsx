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

  describe('player dot colors', () => {
    it('renders all player dots with uniform emerald color (not position-based)', () => {
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

      const { container } = render(
        <SquadPitch picks={picks} players={playersMap} teams={teamsMap} />
      )

      // All player dots should have emerald background, not position-specific colors
      const playerDots = container.querySelectorAll('.bg-emerald-600')
      expect(playerDots.length).toBeGreaterThanOrEqual(4)

      // Should NOT have position-based colors
      expect(container.querySelector('.from-yellow-500')).toBeNull()
      expect(container.querySelector('.from-green-500')).toBeNull()
      expect(container.querySelector('.from-blue-500')).toBeNull()
      expect(container.querySelector('.from-red-500')).toBeNull()
    })
  })

  describe('player dot content', () => {
    it('does not show points/numbers inside the player dot', () => {
      const player = createPlayer({ id: 1, total_points: 150, web_name: 'Salah' })
      const playersMap = new Map([[1, player]])
      const picks = [createPick({ element: 1, position: 1 })]

      const { container } = render(
        <SquadPitch picks={picks} players={playersMap} teams={teamsMap} />
      )

      // The player dot itself should be empty (no text content)
      const playerDot = container.querySelector('.bg-emerald-600')
      expect(playerDot).toBeTruthy()
      expect(playerDot?.textContent).toBe('')
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

      expect(screen.getByText('BENCH')).toBeInTheDocument()
      expect(screen.getByText('BenchGuy')).toBeInTheDocument()
    })
  })
})
