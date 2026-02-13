import { test, expect } from '@playwright/test'

test.describe('Season Review', () => {
  test.beforeEach(async ({ page }) => {
    // Mock the API responses
    await page.route('**/api/players', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          players: [
            { id: 1, web_name: 'Salah', team: 1, element_type: 3, now_cost: 130, total_points: 150 },
          ],
          teams: [{ id: 1, short_name: 'LIV' }],
        }),
      })
    })

    await page.route('**/api/managers/*/history', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          current: [
            { event: 1, points: 65, total_points: 65, overall_rank: 500000, event_transfers: 0, event_transfers_cost: 0, points_on_bench: 5, value: 1000, bank: 0 },
            { event: 2, points: 72, total_points: 137, overall_rank: 400000, event_transfers: 1, event_transfers_cost: 0, points_on_bench: 8, value: 1005, bank: 5 },
            { event: 3, points: 45, total_points: 182, overall_rank: 350000, event_transfers: 2, event_transfers_cost: 4, points_on_bench: 12, value: 1010, bank: 0 },
          ],
          chips: [],
        }),
      })
    })

    await page.route('**/api/managers/*/picks/*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          active_chip: null,
          automatic_subs: [],
          entry_history: {
            event: 3,
            points: 45,
            total_points: 182,
            overall_rank: 350000,
            rank: null,
            rank_sort: null,
            bank: 0,
            value: 1010,
            event_transfers: 2,
            event_transfers_cost: 4,
            points_on_bench: 12,
          },
          picks: [
            { element: 1, position: 1, multiplier: 2, is_captain: true, is_vice_captain: false },
          ],
        }),
      })
    })

    await page.route('**/api/managers/*/season-analysis', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          manager_id: 12345,
          generated_at: '2026-02-13T00:00:00Z',
          gameweeks: [
            {
              gameweek: 1,
              actual_points: 65,
              expected_points: 60,
              luck_delta: 5,
              overall_rank: 500000,
              event_transfers: 0,
              event_transfers_cost: 0,
              captain_impact: { captain_id: 1, multiplier: 2, actual_gain: 10, expected_gain: 8, luck_delta: 2 },
              chip_impact: { chips: [], active: null },
            },
            {
              gameweek: 2,
              actual_points: 72,
              expected_points: 69,
              luck_delta: 3,
              overall_rank: 400000,
              event_transfers: 1,
              event_transfers_cost: 0,
              captain_impact: { captain_id: 1, multiplier: 2, actual_gain: 12, expected_gain: 10, luck_delta: 2 },
              chip_impact: { chips: [], active: null },
            },
            {
              gameweek: 3,
              actual_points: 45,
              expected_points: 52,
              luck_delta: -7,
              overall_rank: 350000,
              event_transfers: 2,
              event_transfers_cost: 4,
              captain_impact: { captain_id: 1, multiplier: 2, actual_gain: 4, expected_gain: 9, luck_delta: -5 },
              chip_impact: { chips: [], active: null },
            },
          ],
          transfer_analytics: [
            { gameweek: 2, transfer_count: 1, transfer_cost: 0, foresight_gain: 1, hindsight_gain: 2, net_gain: 2 },
            { gameweek: 3, transfer_count: 2, transfer_cost: 4, foresight_gain: -1, hindsight_gain: -2, net_gain: -6 },
          ],
          benchmarks: {
            overall: [
              { gameweek: 1, points: 55 },
              { gameweek: 2, points: 57 },
              { gameweek: 3, points: 49 },
            ],
            top_10k: [
              { gameweek: 1, points: 62 },
              { gameweek: 2, points: 66 },
              { gameweek: 3, points: 58 },
            ],
          },
          summary: {
            actual_points: 182,
            expected_points: 181,
            luck_delta: 1,
            captain_actual_gain: 26,
            captain_expected_gain: 27,
            captain_luck_delta: -1,
            transfer_foresight_gain: 0,
            transfer_hindsight_gain: 0,
            transfer_net_gain: -4,
          },
        }),
      })
    })

    await page.route('**/api/leagues/*/season-analysis**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          league: { id: 777, name: 'Mock League' },
          gw_from: 1,
          gw_to: 3,
          gameweek_axis: [1, 2, 3],
          manager_count: 3,
          managers: [],
          benchmarks: [
            { gameweek: 1, mean_actual_points: 56, median_actual_points: 56, mean_expected_points: 54, median_expected_points: 54 },
            { gameweek: 2, mean_actual_points: 60, median_actual_points: 60, mean_expected_points: 58, median_expected_points: 58 },
            { gameweek: 3, mean_actual_points: 48, median_actual_points: 48, mean_expected_points: 50, median_expected_points: 50 },
          ],
        }),
      })
    })

    await page.route('**/api/managers/*', async (route) => {
      if (route.request().url().includes('/history') || route.request().url().includes('/picks/') || route.request().url().includes('/season-analysis')) return
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 12345,
          player_first_name: 'Test',
          player_last_name: 'User',
          name: 'Test FC',
          summary_overall_points: 182,
          summary_overall_rank: 350000,
          current_event: 3,
          leagues: {
            classic: [{ id: 777 }],
            h2h: [],
          },
        }),
      })
    })
  })

  test('displays Unicode characters correctly in Season Insights', async ({ page }) => {
    await page.goto('/')

    // Search for a manager
    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Analyze")')

    // Wait for data to load
    await page.waitForSelector('text=Season Insights', { timeout: 10000 })

    // Get the Season Insights card
    const insightsCard = page.locator('text=Season Insights').locator('..')

    // Verify Unicode characters render correctly (not as escape sequences)
    // Check for up/down arrows in rank movement
    const hasArrow = await page.locator('text=↑').or(page.locator('text=↓')).first().isVisible()
    expect(hasArrow).toBe(true)

    // Verify sigma character exists
    await expect(page.locator('text=σ =')).toBeVisible()

    // Verify pound sign in value change
    await expect(page.locator('text=£').first()).toBeVisible()

    // Verify right arrow exists (→)
    await expect(page.locator('text=→')).toBeVisible()

    // Verify NO escape sequences are visible (these would indicate broken Unicode)
    const pageContent = await page.content()
    expect(pageContent).not.toContain('\\u2191')
    expect(pageContent).not.toContain('\\u2192')
    expect(pageContent).not.toContain('\\u03C3')
    expect(pageContent).not.toContain('\\u00A3')
  })

  test('Gameweek table has aligned columns', async ({ page }) => {
    await page.goto('/')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Analyze")')

    await page.waitForSelector('text=Gameweek Breakdown', { timeout: 10000 })

    // Get the Gameweek Breakdown card specifically
    const gameweekCard = page.locator('h3:has-text("Gameweek Breakdown")').first().locator('..').locator('..')
    const table = gameweekCard.locator('table').first()

    // Verify table headers exist
    const headers = ['GW', 'Pts', 'Total', 'Rank', 'Move', 'TF', 'Hits', 'Bench']
    for (const header of headers) {
      await expect(table.locator(`th:has-text("${header}")`)).toBeVisible()
    }

    // Verify table has data rows (at least one)
    const rows = table.locator('tbody tr')
    const rowCount = await rows.count()
    expect(rowCount).toBeGreaterThan(0)

    // Verify arrow indicators exist in the table
    const tableContent = await table.textContent()
    expect(tableContent).toMatch(/[↑↓]/)
  })

  test('Gameweek table is centered and not full-width stretched', async ({ page }) => {
    await page.goto('/')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Analyze")')

    await page.waitForSelector('text=Gameweek Breakdown', { timeout: 10000 })

    // Get the Gameweek Breakdown table specifically
    const gameweekSection = page.locator('text=Gameweek Breakdown').locator('..').locator('..')
    const table = gameweekSection.locator('table.table-broadcast')

    // Check that table has w-auto class (not full width)
    await expect(table).toHaveClass(/w-auto/)

    // Check that table is centered
    await expect(table).toHaveClass(/mx-auto/)
  })

  test('expected vs actual panel supports benchmark toggles', async ({ page }) => {
    await page.goto('/')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Analyze")')

    await page.waitForSelector('text=Expected vs Actual', { timeout: 10000 })
    await expect(page.locator('[data-testid="expected-actual-table"]')).toBeVisible()

    await page.selectOption('#season-benchmark-select', 'top_10k')
    await expect(page.locator('text=Cumulative vs Benchmark')).toBeVisible()

    await page.selectOption('#season-benchmark-select', 'league_median')
    await expect(page.locator('[data-testid="expected-actual-table"]')).toContainText('56.0')
  })

  test('transfer quality scorecard shows only transfer weeks with aggregate totals', async ({ page }) => {
    await page.goto('/')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Analyze")')

    await page.waitForSelector('text=Transfer Quality', { timeout: 10000 })
    const table = page.locator('[data-testid="transfer-quality-table"]')
    await expect(table).toBeVisible()

    const rows = table.locator('tbody tr')
    await expect(rows).toHaveCount(2)
    await expect(rows.nth(0)).toContainText('2')
    await expect(rows.nth(1)).toContainText('3')
  })

  test('loads manager automatically from URL manager param', async ({ page }) => {
    await page.goto('/?tab=season-review&manager=12345')

    await page.waitForSelector('text=Season Insights', { timeout: 10000 })
    await expect(page.locator('h3:has-text("Test FC")')).toBeVisible()
    await expect(page.locator('text=Rank:')).toBeVisible()
    await expect(page).toHaveURL(/manager=12345/)
  })
})
