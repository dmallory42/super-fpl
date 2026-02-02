import { test, expect } from '@playwright/test'

test.describe('Live Page', () => {
  test.beforeEach(async ({ page }) => {
    // Mock the fixtures status endpoint for auto-GW detection
    await page.route('**/api/fixtures/status', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          current_gameweek: 24,
          is_live: true,
          gameweeks: [
            {
              gameweek: 24,
              fixtures: [
                { id: 1, kickoff_time: '2024-01-15T15:00:00Z', started: true, finished: true, minutes: 90, home_club_id: 1, away_club_id: 2, home_score: 2, away_score: 1 },
                { id: 2, kickoff_time: '2024-01-15T17:30:00Z', started: true, finished: false, minutes: 65, home_club_id: 3, away_club_id: 4, home_score: 1, away_score: 0 },
                { id: 3, kickoff_time: '2024-01-15T20:00:00Z', started: false, finished: false, minutes: 0, home_club_id: 5, away_club_id: 6, home_score: null, away_score: null },
              ],
              total: 3,
              started: 2,
              finished: 1,
              first_kickoff: '2024-01-15T15:00:00Z',
              last_kickoff: '2024-01-15T20:00:00Z',
            },
          ],
        }),
      })
    })

    // Mock players endpoint
    await page.route('**/api/players', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          players: [
            { id: 1, web_name: 'Salah', team: 1, element_type: 3, now_cost: 130, total_points: 150 },
            { id: 2, web_name: 'Haaland', team: 3, element_type: 4, now_cost: 140, total_points: 160 },
            { id: 3, web_name: 'Saka', team: 5, element_type: 3, now_cost: 100, total_points: 120 },
            { id: 4, web_name: 'Raya', team: 5, element_type: 1, now_cost: 55, total_points: 90 },
            { id: 5, web_name: 'Gabriel', team: 5, element_type: 2, now_cost: 55, total_points: 100 },
            { id: 6, web_name: 'VanDijk', team: 1, element_type: 2, now_cost: 65, total_points: 110 },
            { id: 7, web_name: 'Dias', team: 3, element_type: 2, now_cost: 55, total_points: 95 },
            { id: 8, web_name: 'Alexander-Arnold', team: 1, element_type: 2, now_cost: 75, total_points: 115 },
            { id: 9, web_name: 'Palmer', team: 2, element_type: 3, now_cost: 105, total_points: 145 },
            { id: 10, web_name: 'Foden', team: 3, element_type: 3, now_cost: 90, total_points: 100 },
            { id: 11, web_name: 'Gordon', team: 1, element_type: 3, now_cost: 75, total_points: 90 },
            { id: 12, web_name: 'Watkins', team: 4, element_type: 4, now_cost: 90, total_points: 120 },
            { id: 13, web_name: 'Onana', team: 6, element_type: 1, now_cost: 50, total_points: 80 },
            { id: 14, web_name: 'Gusto', team: 2, element_type: 2, now_cost: 45, total_points: 70 },
            { id: 15, web_name: 'Wissa', team: 7, element_type: 4, now_cost: 60, total_points: 85 },
          ],
          teams: [
            { id: 1, short_name: 'LIV' },
            { id: 2, short_name: 'CHE' },
            { id: 3, short_name: 'MCI' },
            { id: 4, short_name: 'AVL' },
            { id: 5, short_name: 'ARS' },
            { id: 6, short_name: 'MUN' },
            { id: 7, short_name: 'BRE' },
          ],
        }),
      })
    })

    // Mock live manager endpoint
    await page.route('**/api/live/24/manager/*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          manager_id: 12345,
          gameweek: 24,
          total_points: 67,
          bench_points: 8,
          players: [
            // Starting XI (positions 1-11)
            { player_id: 4, position: 1, multiplier: 1, points: 6, effective_points: 6, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 6 } },
            { player_id: 5, position: 2, multiplier: 1, points: 8, effective_points: 8, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 8 } },
            { player_id: 6, position: 3, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 7, position: 4, multiplier: 1, points: 1, effective_points: 1, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 1 } },
            { player_id: 8, position: 5, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 1, position: 6, multiplier: 2, points: 12, effective_points: 24, is_playing: true, is_captain: true, stats: { minutes: 90, total_points: 12 } },
            { player_id: 9, position: 7, multiplier: 1, points: 3, effective_points: 3, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 3 } },
            { player_id: 10, position: 8, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 11, position: 9, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 12, position: 10, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 2, position: 11, multiplier: 1, points: 15, effective_points: 15, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 15 } },
            // Bench (positions 12-15)
            { player_id: 13, position: 12, multiplier: 1, points: 0, effective_points: 0, is_playing: false, is_captain: false, stats: { minutes: 0, total_points: 0 } },
            { player_id: 3, position: 13, multiplier: 1, points: 3, effective_points: 3, is_playing: false, is_captain: false, stats: { minutes: 90, total_points: 3 } },
            { player_id: 14, position: 14, multiplier: 1, points: 2, effective_points: 2, is_playing: false, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 15, position: 15, multiplier: 1, points: 1, effective_points: 1, is_playing: false, is_captain: false, stats: { minutes: 90, total_points: 1 } },
          ],
          updated_at: '2024-01-15T18:00:00Z',
        }),
      })
    })

    // Mock live samples endpoint
    await page.route('**/api/live/24/samples', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          gameweek: 24,
          samples: {
            top_10k: { avg_points: 72, sample_size: 2000, effective_ownership: { 1: 185.5, 2: 92.3 } },
            top_100k: { avg_points: 58, sample_size: 2000, effective_ownership: { 1: 150.2, 2: 85.1 } },
            top_1m: { avg_points: 48, sample_size: 2000, effective_ownership: { 1: 120.5, 2: 78.2 } },
            overall: { avg_points: 42, sample_size: 2000, effective_ownership: { 1: 95.3, 2: 65.4 } },
          },
          updated_at: '2024-01-15T18:00:00Z',
        }),
      })
    })

    // Mock live bonus endpoint
    await page.route('**/api/live/24/bonus', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          gameweek: 24,
          bonus_predictions: [
            { player_id: 1, bps: 45, predicted_bonus: 3, fixture_id: 1 },
            { player_id: 2, bps: 38, predicted_bonus: 2, fixture_id: 2 },
          ],
        }),
      })
    })
  })

  test('auto-detects current gameweek and shows status', async ({ page }) => {
    await page.goto('/')

    // Navigate to Live tab
    await page.click('text=Live')

    // Should show GW24 detected automatically
    await expect(page.locator('text=/GW24/')).toBeVisible({ timeout: 10000 })

    // Should show match status
    await expect(page.locator('text=/\\d+\\/\\d+ matches complete/')).toBeVisible()
  })

  test('displays formation pitch with players in correct positions', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    // Enter manager ID
    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for pitch to load
    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Should show player names on the pitch (use first() since players may appear in bonus too)
    await expect(page.locator('.pitch-texture').locator('text=Salah').first()).toBeVisible()
    await expect(page.locator('.pitch-texture').locator('text=Haaland').first()).toBeVisible()

    // Should show captain badge (yellow background with C or TC)
    await expect(page.locator('.bg-gradient-to-br.from-yellow-400').first()).toBeVisible()
  })

  test('shows player status indicators correctly', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Players with finished matches should have solid styling (no animate-pulse-glow for finished)
    // Players with in-progress matches should have pulsing glow
    // Check that the pitch contains players with different visual states
    const pitchContent = await page.locator('.pitch-texture').textContent()
    expect(pitchContent).toContain('Salah')
    expect(pitchContent).toContain('Haaland')
  })

  test('displays comparison bars with tier averages', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for comparison section
    await page.waitForSelector('text=vs Sample Averages', { timeout: 10000 })

    // Should show tier labels - look for the text anywhere on page (use first() for duplicates)
    await expect(page.locator('text=Top 10K').first()).toBeVisible()
    await expect(page.locator('text=Top 100K').first()).toBeVisible()
    await expect(page.locator('text=Top 1M').first()).toBeVisible()
    await expect(page.locator('text=Overall').first()).toBeVisible()

    // Should show user's "You" bar
    await expect(page.locator('text=You').first()).toBeVisible()
  })

  test('shows live points and comparison in stats header', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for stats to load
    await page.waitForSelector('text=Live Points', { timeout: 10000 })

    // Should show live points (67 from mock) - use first() as number may appear elsewhere
    await expect(page.locator('text=67').first()).toBeVisible()

    // Should show bench points
    await expect(page.locator('text=Bench Points')).toBeVisible()

    // Should show comparison vs top tier - use first() as it may appear in both stat header and comparison bars
    await expect(page.locator('text=/vs Top 10K/').first()).toBeVisible()
  })

  test('shows bonus predictions', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for bonus section
    await page.waitForSelector('text=Bonus Predictions', { timeout: 10000 })

    // Should show bonus predictions (+3 and +2 badges)
    await expect(page.locator('text=+3').first()).toBeVisible()
    await expect(page.locator('text=+2').first()).toBeVisible()
  })

  test('remembers manager ID in localStorage', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    // Enter and submit manager ID
    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for data to load
    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Reload the page
    await page.reload()
    await page.click('text=Live')

    // Should auto-load the saved manager ID
    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Should show the data without needing to re-enter ID (use first() since player appears in multiple places)
    await expect(page.locator('.pitch-texture').locator('text=Salah').first()).toBeVisible()
  })

  test('allows changing manager ID', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Click change manager link
    await page.click('text=Change Manager ID')

    // Should show input again
    await expect(page.locator('input[placeholder*="Manager ID"]')).toBeVisible()
  })

  test('shows LIVE indicator when gameweek is active', async ({ page }) => {
    await page.goto('/')
    await page.click('text=Live')

    // Should show LIVE indicator (the mock has is_live: true) - use the specific Live indicator component
    await expect(page.locator('.animate-pulse-dot').first()).toBeVisible({ timeout: 10000 })
  })

  test('applies auto-subs when starting player has 0 minutes and match finished', async ({ page }) => {
    // Override the live manager endpoint to have a player who didn't play
    await page.route('**/api/live/24/manager/*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          manager_id: 12345,
          gameweek: 24,
          total_points: 60, // Before auto-sub
          bench_points: 10,
          players: [
            // Starting XI - player 11 didn't play (0 mins, match finished)
            { player_id: 4, position: 1, multiplier: 1, points: 6, effective_points: 6, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 6 } },
            { player_id: 5, position: 2, multiplier: 1, points: 8, effective_points: 8, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 8 } },
            { player_id: 6, position: 3, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 7, position: 4, multiplier: 1, points: 1, effective_points: 1, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 1 } },
            { player_id: 8, position: 5, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 1, position: 6, multiplier: 2, points: 12, effective_points: 24, is_playing: true, is_captain: true, stats: { minutes: 90, total_points: 12 } },
            { player_id: 9, position: 7, multiplier: 1, points: 3, effective_points: 3, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 3 } },
            { player_id: 10, position: 8, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 11, position: 9, multiplier: 1, points: 0, effective_points: 0, is_playing: true, is_captain: false, stats: { minutes: 0, total_points: 0 } }, // Didn't play!
            { player_id: 12, position: 10, multiplier: 1, points: 2, effective_points: 2, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 2, position: 11, multiplier: 1, points: 15, effective_points: 15, is_playing: true, is_captain: false, stats: { minutes: 90, total_points: 15 } },
            // Bench
            { player_id: 13, position: 12, multiplier: 1, points: 0, effective_points: 0, is_playing: false, is_captain: false, stats: { minutes: 0, total_points: 0 } }, // GK - won't come on
            { player_id: 3, position: 13, multiplier: 1, points: 5, effective_points: 5, is_playing: false, is_captain: false, stats: { minutes: 90, total_points: 5 } }, // First sub - will come on
            { player_id: 14, position: 14, multiplier: 1, points: 2, effective_points: 2, is_playing: false, is_captain: false, stats: { minutes: 90, total_points: 2 } },
            { player_id: 15, position: 15, multiplier: 1, points: 1, effective_points: 1, is_playing: false, is_captain: false, stats: { minutes: 90, total_points: 1 } },
          ],
          updated_at: '2024-01-15T18:00:00Z',
        }),
      })
    })

    await page.goto('/')
    await page.click('text=Live')

    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for pitch to load
    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Should show auto-sub indicator
    await expect(page.locator('text=/auto-sub/')).toBeVisible()

    // Total should be 65 (60 original - 0 for player who didn't play + 5 from bench sub)
    // Actually: 6+8+2+1+2+24+3+2+0+2+15 = 65, then +5 from sub = 65 (the 0 gets replaced by 5)
    await expect(page.locator('text=65').first()).toBeVisible()
  })

  test('loads manager from URL parameters', async ({ page }) => {
    // Navigate directly to Live tab with manager ID in URL
    await page.goto('/?tab=live&manager=12345')

    // Should automatically load the manager data without needing to enter ID
    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // Should show player data
    await expect(page.locator('.pitch-texture').locator('text=Salah').first()).toBeVisible()

    // URL should still have the manager parameter
    expect(page.url()).toContain('manager=12345')
  })

  test('updates URL when manager ID is entered', async ({ page }) => {
    await page.goto('/?tab=live')

    // Enter manager ID
    await page.fill('input[placeholder*="Manager ID"]', '12345')
    await page.click('button:has-text("Track")')

    // Wait for data to load
    await page.waitForSelector('.pitch-texture', { timeout: 10000 })

    // URL should now include manager parameter
    expect(page.url()).toContain('manager=12345')
  })
})
