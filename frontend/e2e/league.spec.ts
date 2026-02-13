import { test, expect } from '@playwright/test'

test.describe('League Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/players', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          players: [
            { id: 1, web_name: 'Salah', team: 1, element_type: 3, now_cost: 130, total_points: 150 },
            { id: 2, web_name: 'Haaland', team: 2, element_type: 4, now_cost: 140, total_points: 160 },
          ],
          teams: [
            { id: 1, short_name: 'LIV' },
            { id: 2, short_name: 'MCI' },
          ],
        }),
      })
    })
  })

  test('loads deep-linked decisions view and preserves league state', async ({ page }) => {
    await page.route('**/api/leagues/777/season-analysis**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          league: { id: 777, name: 'Mini League' },
          gw_from: 1,
          gw_to: 24,
          gameweek_axis: Array.from({ length: 24 }, (_, i) => i + 1),
          manager_count: 2,
          managers: [
            {
              manager_id: 101,
              manager_name: 'Alex',
              team_name: 'Alpha FC',
              rank: 1,
              total: 1500,
              gameweeks: [
                {
                  gameweek: 24,
                  actual_points: 64,
                  expected_points: 60,
                  luck_delta: 4,
                  event_transfers: 1,
                  event_transfers_cost: 0,
                  captain_actual_gain: 8,
                  missing: false,
                },
              ],
              decision_quality: {
                captain_gains: 30,
                hit_cost: 8,
                transfer_net_gain: 12,
                hit_roi: 1.5,
                chip_events: 1,
              },
            },
            {
              manager_id: 202,
              manager_name: 'Ben',
              team_name: 'Beta FC',
              rank: 2,
              total: 1450,
              gameweeks: [
                {
                  gameweek: 24,
                  actual_points: 58,
                  expected_points: 59,
                  luck_delta: -1,
                  event_transfers: 2,
                  event_transfers_cost: 4,
                  captain_actual_gain: 2,
                  missing: false,
                },
              ],
              decision_quality: {
                captain_gains: 15,
                hit_cost: 12,
                transfer_net_gain: -2,
                hit_roi: -0.167,
                chip_events: 0,
              },
            },
          ],
          benchmarks: [],
        }),
      })
    })

    await page.goto('/?tab=league-analyzer&league_id=777&league_view=decisions&league_gw=24')

    await expect(page.getByText('Decision Quality')).toBeVisible({ timeout: 10000 })
    await expect(page.locator('#gameweek-select')).toHaveValue('24')
    await expect(page.locator('input[aria-label="League ID"]')).toHaveValue('777')
    await expect(page).toHaveURL(/league_view=decisions/)
  })

  test('reuses cached data when switching league subtabs', async ({ page }) => {
    let analysisRequests = 0
    let seasonRequests = 0

    await page.route('**/api/leagues/777/analysis**', async (route) => {
      analysisRequests += 1
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          league: { id: 777, name: 'Mini League' },
          gameweek: 24,
          managers: [
            { id: 101, name: 'Alex', team_name: 'Alpha FC', rank: 1, total: 1500 },
            { id: 202, name: 'Ben', team_name: 'Beta FC', rank: 2, total: 1450 },
          ],
          comparison: {
            gameweek: 24,
            manager_count: 2,
            effective_ownership: { 1: 120.5, 2: 110.2 },
            differentials: {
              101: [{ player_id: 1, eo: 120.5, is_captain: true, multiplier: 2 }],
              202: [{ player_id: 2, eo: 110.2, is_captain: false, multiplier: 1 }],
            },
            risk_scores: {
              101: {
                score: 42,
                level: 'medium',
                breakdown: { captain_risk: 21, playing_count: 11 },
              },
              202: {
                score: 35,
                level: 'low',
                breakdown: { captain_risk: 15, playing_count: 11 },
              },
            },
            ownership_matrix: {
              101: { 1: 2, 2: 1 },
              202: { 1: 1, 2: 2 },
            },
            players: {
              1: {
                id: 1,
                web_name: 'Salah',
                team: 1,
                position: 3,
                now_cost: 130,
                total_points: 150,
              },
              2: {
                id: 2,
                web_name: 'Haaland',
                team: 2,
                position: 4,
                now_cost: 140,
                total_points: 160,
              },
            },
          },
        }),
      })
    })

    await page.route('**/api/leagues/777/season-analysis**', async (route) => {
      seasonRequests += 1
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          league: { id: 777, name: 'Mini League' },
          gw_from: 1,
          gw_to: 24,
          gameweek_axis: Array.from({ length: 24 }, (_, i) => i + 1),
          manager_count: 2,
          managers: [
            {
              manager_id: 101,
              manager_name: 'Alex',
              team_name: 'Alpha FC',
              rank: 1,
              total: 1500,
              gameweeks: [
                {
                  gameweek: 24,
                  actual_points: 64,
                  expected_points: 60,
                  luck_delta: 4,
                  event_transfers: 1,
                  event_transfers_cost: 0,
                  captain_actual_gain: 8,
                  missing: false,
                },
              ],
              decision_quality: {
                captain_gains: 30,
                hit_cost: 8,
                transfer_net_gain: 12,
                hit_roi: 1.5,
                chip_events: 1,
              },
            },
            {
              manager_id: 202,
              manager_name: 'Ben',
              team_name: 'Beta FC',
              rank: 2,
              total: 1450,
              gameweeks: [
                {
                  gameweek: 24,
                  actual_points: 58,
                  expected_points: 59,
                  luck_delta: -1,
                  event_transfers: 2,
                  event_transfers_cost: 4,
                  captain_actual_gain: 2,
                  missing: false,
                },
              ],
              decision_quality: {
                captain_gains: 15,
                hit_cost: 12,
                transfer_net_gain: -2,
                hit_roi: -0.167,
                chip_events: 0,
              },
            },
          ],
          benchmarks: [],
        }),
      })
    })

    await page.goto('/?tab=league-analyzer&league_id=777&league_view=this-gw')
    await expect(page.getByText('League Standings')).toBeVisible({ timeout: 10000 })

    await page.getByTestId('league-view-tab-season').click()
    await expect(page.getByText('Season Trajectory')).toBeVisible()

    await page.getByTestId('league-view-tab-decisions').click()
    await expect(page.getByText('Decision Quality')).toBeVisible()

    await page.getByTestId('league-view-tab-season').click()
    await expect(page.getByText('Season Trajectory')).toBeVisible()

    await page.getByTestId('league-view-tab-this-gw').click()
    await expect(page.getByText('League Standings')).toBeVisible()

    expect(analysisRequests).toBe(1)
    expect(seasonRequests).toBe(1)
  })
})
