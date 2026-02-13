import { test, expect } from '@playwright/test'

test.describe('Planner Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/players', async (route) => {
      const players = [
        { id: 1, web_name: 'Raya', team: 1, element_type: 1, now_cost: 55, total_points: 90 },
        { id: 2, web_name: 'Turner', team: 2, element_type: 1, now_cost: 40, total_points: 45 },
        { id: 3, web_name: 'Gabriel', team: 1, element_type: 2, now_cost: 60, total_points: 120 },
        { id: 4, web_name: 'Saliba', team: 1, element_type: 2, now_cost: 58, total_points: 118 },
        { id: 5, web_name: 'Gvardiol', team: 3, element_type: 2, now_cost: 56, total_points: 102 },
        { id: 6, web_name: 'Mykolenko', team: 4, element_type: 2, now_cost: 45, total_points: 85 },
        { id: 7, web_name: 'Burn', team: 5, element_type: 2, now_cost: 46, total_points: 80 },
        { id: 8, web_name: 'Saka', team: 1, element_type: 3, now_cost: 100, total_points: 160 },
        { id: 9, web_name: 'Foden', team: 3, element_type: 3, now_cost: 90, total_points: 140 },
        { id: 10, web_name: 'Palmer', team: 6, element_type: 3, now_cost: 105, total_points: 170 },
        { id: 11, web_name: 'Bowen', team: 7, element_type: 3, now_cost: 80, total_points: 130 },
        { id: 12, web_name: 'Mbeumo', team: 8, element_type: 3, now_cost: 76, total_points: 125 },
        { id: 13, web_name: 'Haaland', team: 3, element_type: 4, now_cost: 150, total_points: 180 },
        { id: 14, web_name: 'Watkins', team: 9, element_type: 4, now_cost: 90, total_points: 145 },
        { id: 15, web_name: 'Solanke', team: 10, element_type: 4, now_cost: 74, total_points: 118 },
      ]

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          players,
          teams: [
            { id: 1, short_name: 'ARS' },
            { id: 2, short_name: 'NFO' },
            { id: 3, short_name: 'MCI' },
            { id: 4, short_name: 'EVE' },
            { id: 5, short_name: 'NEW' },
            { id: 6, short_name: 'CHE' },
            { id: 7, short_name: 'WHU' },
            { id: 8, short_name: 'BRE' },
            { id: 9, short_name: 'AVL' },
            { id: 10, short_name: 'BOU' },
          ],
        }),
      })
    })

    await page.route('**/api/predictions/range**', async (route) => {
      const gameweeks = [27, 28]
      const players = Array.from({ length: 15 }, (_, idx) => {
        const playerId = idx + 1
        return {
          player_id: playerId,
          web_name: `P${playerId}`,
          team: Math.min(10, Math.max(1, playerId - 1)),
          position: playerId <= 2 ? 1 : playerId <= 7 ? 2 : playerId <= 12 ? 3 : 4,
          now_cost: 50 + playerId,
          form: 5,
          total_points: 100,
          expected_mins: { 27: 90, 28: 90 },
          expected_mins_if_fit: 90,
          predictions: { 27: 4 + (playerId % 3), 28: 4 + ((playerId + 1) % 3) },
          if_fit_predictions: { 27: 4 + (playerId % 3), 28: 4 + ((playerId + 1) % 3) },
          total_predicted: 10,
        }
      })

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          gameweeks,
          current_gameweek: 27,
          players,
          fixtures: {},
          generated_at: '2026-02-13T00:00:00Z',
        }),
      })
    })

    await page.route('**/api/live/*/samples', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          gameweek: 27,
          samples: {
            top_10k: { avg_points: 55, sample_size: 1000, effective_ownership: {} },
          },
          updated_at: '2026-02-13T00:00:00Z',
        }),
      })
    })

    await page.route('**/api/planner/optimize**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          current_gameweek: 27,
          planning_horizon: [27, 28],
          current_squad: {
            player_ids: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            bank: 1.5,
            squad_value: 102.4,
            free_transfers: 1,
            api_free_transfers: 1,
            predicted_points: { 27: 58, 28: 56, total: 114 },
          },
          recommendations: [],
          chip_suggestions_ranked: {},
          chip_mode: 'locked',
          requested_chip_plan: [],
          resolved_chip_plan: [],
          chip_plan: [],
          comparisons: null,
          paths: [],
        }),
      })
    })
  })

  test('loads planner data without JSON parse error', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Planner')

    await page.fill('input[placeholder="Enter FPL ID"]', '8028')
    await page.click('button:has-text("Load")')

    await expect(page.locator('text=Squad Value')).toBeVisible({ timeout: 10000 })
    await expect(page.locator('text=JSON.parse')).toHaveCount(0)
  })

  test('sends objective mode and changes top plan across modes', async ({ page }) => {
    const seenObjectives: string[] = []

    await page.route('**/api/planner/optimize**', async (route) => {
      const url = new URL(route.request().url())
      const objective = url.searchParams.get('objective') ?? 'missing'
      const skipSolve = url.searchParams.get('skip_solve') === '1'
      seenObjectives.push(objective)

      const base = {
        current_gameweek: 27,
        planning_horizon: [27, 28],
        current_squad: {
          player_ids: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
          bank: 1.5,
          squad_value: 102.4,
          free_transfers: 1,
          api_free_transfers: 1,
          predicted_points: { 27: 58, 28: 56, total: 114 },
        },
        recommendations: [],
        chip_suggestions_ranked: {},
        chip_mode: 'locked',
        objective_mode: objective,
        requested_chip_plan: [],
        resolved_chip_plan: [],
        chip_plan: [],
        comparisons: null,
      }

      if (skipSolve) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ ...base, paths: [] }),
        })
        return
      }

      const expectedPaths = [
        {
          id: 1,
          total_score: 120,
          score_vs_hold: 4.5,
          total_hits: 0,
          transfers_by_gw: {
            27: {
              action: 'transfer',
              ft_available: 1,
              ft_after: 1,
              moves: [
                {
                  out_id: 8,
                  out_name: 'Saka',
                  out_team: 1,
                  out_price: 10,
                  in_id: 16,
                  in_name: 'SafeMid',
                  in_team: 3,
                  in_price: 10,
                  gain: 2,
                  is_free: true,
                },
              ],
              hit_cost: 0,
              gw_score: 62,
              squad_ids: [1, 2, 3],
              bank: 1.5,
              chip_played: null,
            },
            28: {
              action: 'bank',
              ft_available: 1,
              ft_after: 2,
              moves: [],
              hit_cost: 0,
              gw_score: 58,
              squad_ids: [1, 2, 3],
              bank: 1.5,
              chip_played: null,
            },
          },
        },
      ]

      const ceilingPaths = [
        {
          id: 1,
          total_score: 121,
          score_vs_hold: 5.0,
          total_hits: 0,
          transfers_by_gw: {
            27: {
              action: 'transfer',
              ft_available: 1,
              ft_after: 1,
              moves: [
                {
                  out_id: 8,
                  out_name: 'Saka',
                  out_team: 1,
                  out_price: 10,
                  in_id: 17,
                  in_name: 'BoomBustMid',
                  in_team: 4,
                  in_price: 10,
                  gain: 1.5,
                  is_free: true,
                },
              ],
              hit_cost: 0,
              gw_score: 63,
              squad_ids: [1, 2, 3],
              bank: 1.5,
              chip_played: null,
            },
            28: {
              action: 'bank',
              ft_available: 1,
              ft_after: 2,
              moves: [],
              hit_cost: 0,
              gw_score: 58,
              squad_ids: [1, 2, 3],
              bank: 1.5,
              chip_played: null,
            },
          },
        },
      ]

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ...base,
          paths: objective === 'ceiling' ? ceilingPaths : expectedPaths,
        }),
      })
    })

    await page.goto('/')
    await page.click('text=Planner')
    await page.fill('input[placeholder="Enter FPL ID"]', '8028')
    await page.click('button:has-text("Load")')

    await page.click('button:has-text("Find Plans")')
    await expect(page.locator('text=Objective: Expected')).toBeVisible({ timeout: 10000 })
    await expect(page.locator('text=SafeMid')).toBeVisible()

    await page.click('button:has-text("Ceiling")')
    await page.click('button:has-text("Re-solve")')
    await expect(page.locator('text=Objective: Ceiling')).toBeVisible({ timeout: 10000 })
    await expect(page.locator('text=BoomBustMid')).toBeVisible()

    expect(seenObjectives).toContain('expected')
    expect(seenObjectives).toContain('ceiling')
    expect(seenObjectives).not.toContain('missing')
  })

  test('sends constraints and shows infeasible-constraint message', async ({ page }) => {
    let sawConstraints = false

    await page.route('**/api/planner/optimize**', async (route) => {
      const url = new URL(route.request().url())
      const constraintsRaw = url.searchParams.get('constraints')
      const skipSolve = url.searchParams.get('skip_solve') === '1'

      if (constraintsRaw) {
        sawConstraints = true
      }

      if (!skipSolve && constraintsRaw) {
        await route.fulfill({
          status: 400,
          contentType: 'application/json',
          body: JSON.stringify({
            error:
              'Infeasible constraints: no valid plans satisfy the active lock/avoid/hit/chip-window rules.',
          }),
        })
        return
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          current_gameweek: 27,
          planning_horizon: [27, 28],
          current_squad: {
            player_ids: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            bank: 1.5,
            squad_value: 102.4,
            free_transfers: 1,
            api_free_transfers: 1,
            predicted_points: { 27: 58, 28: 56, total: 114 },
          },
          recommendations: [],
          chip_suggestions_ranked: {},
          chip_mode: 'locked',
          objective_mode: 'expected',
          constraints: {},
          requested_chip_plan: [],
          resolved_chip_plan: [],
          chip_plan: [],
          comparisons: null,
          paths: [],
        }),
      })
    })

    await page.goto('/')
    await page.click('text=Planner')
    await page.fill('input[placeholder="Enter FPL ID"]', '8028')
    await page.click('button:has-text("Load")')

    await page.getByTestId('constraints-lock-ids').fill('999')
    await page.click('button:has-text("Find Plans")')

    await expect(page.locator('text=Infeasible constraints')).toBeVisible({ timeout: 10000 })
    expect(sawConstraints).toBe(true)
  })
})
