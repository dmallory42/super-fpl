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

    await page.route('**/api/entry/*/history', async (route) => {
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

    await page.route('**/api/entry/*', async (route) => {
      if (route.request().url().includes('/history')) return
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
    const gameweekCard = page.locator('section:has-text("Gameweek Breakdown"), div:has-text("Gameweek Breakdown")').first()
    const table = gameweekCard.locator('table')

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
})
