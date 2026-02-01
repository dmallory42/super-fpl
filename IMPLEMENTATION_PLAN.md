# SuperFPL - Fantasy Premier League Analytics Suite

## Decisions Made

| Decision | Choice |
|----------|--------|
| Manager tracking | Auto-cache mini-league members when viewing a league |
| Prediction model | Hybrid: rule-based core with learned adjustments |
| Data sources | FPL API + Bookmaker odds |
| Historical data | 3 seasons |
| Frontend UI | React + Tailwind + shadcn/ui |
| Backend | PHP + SQLite |
| Hosting | DigitalOcean (superfpl.com) |
| Repository | github.com/dmallory42/super-fpl (replace existing) |
| Architecture | Standalone FPL client package (extractable) |

---

## Problem Space Analysis

### Domain Entities

```
┌─────────────┐     owns      ┌─────────────┐
│   Manager   │──────────────▶│    Team     │
│  (Entry)    │               │   (Squad)   │
└─────────────┘               └─────────────┘
      │                             │
      │ history                     │ contains
      ▼                             ▼
┌─────────────┐               ┌─────────────┐
│  GW Picks   │───references──▶│   Player    │
│             │               │  (Element)  │
└─────────────┘               └─────────────┘
                                    │
                                    │ plays for
                                    ▼
                              ┌─────────────┐
                              │    Club     │
                              │   (Team)    │
                              └─────────────┘
                                    │
                                    │ plays in
                                    ▼
                              ┌─────────────┐
                              │   Fixture   │
                              └─────────────┘
```

### Key Data Available from FPL API

| Data Type | Refresh Rate | Size |
|-----------|--------------|------|
| Players (elements) | ~Daily | ~809 players |
| Clubs (teams) | Seasonal | 20 teams |
| Gameweeks (events) | Per GW | 38 events |
| Fixtures | Per match | ~380/season |
| Manager entry | On request | Per user |
| Live GW data | Real-time | During matches |

### Proposed Tool Suite

1. **Team Analyzer** - Analyze a manager's squad composition, value, form
2. **Points Predictor** - Predicted points model using xG, xA, form, FDR
3. **Transfer Planner** - Suggest optimal transfers based on fixtures/form
4. **Manager Comparator** - Compare managers: EO, differentials, risk
5. **Live Tracker** - Real-time rank movement during gameweeks
6. **Mini-League Dashboard** - League-level analytics and projections

---

## Architecture (Hybrid - Option C)

- SQLite for: player stats, fixtures, predictions, tracked managers
- Direct API for: live data, one-off lookups
- PHP API orchestrates both

```
┌────────────────────────────────────────────────────────────────┐
│                        React Frontend                          │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐          │
│  │  Team    │ │ Points   │ │ Transfer │ │  Live    │          │
│  │ Analyzer │ │Predictor │ │ Planner  │ │ Tracker  │          │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘          │
└────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────┐
│                         PHP API                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │                    API Router                             │ │
│  │  /api/players  /api/managers  /api/predict  /api/live    │ │
│  └──────────────────────────────────────────────────────────┘ │
│        │                    │                    │             │
│        ▼                    ▼                    ▼             │
│  ┌──────────┐        ┌──────────┐        ┌──────────┐        │
│  │  SQLite  │        │  Cache   │        │ FPL API  │        │
│  │  Store   │        │  Layer   │        │  Client  │        │
│  └──────────┘        └──────────┘        └──────────┘        │
└────────────────────────────────────────────────────────────────┘
```

### Data Flow Strategy

| Data | Storage | Refresh | Trigger |
|------|---------|---------|---------|
| Players (season totals) | SQLite | Daily | Cron 6am |
| Player GW history | SQLite | Post-GW | Cron after deadline+3h |
| Fixtures + results | SQLite | Post-match | Cron hourly on match days |
| Clubs | SQLite | Seasonal | Manual |
| Fixture odds | SQLite | Pre-GW | Cron day before deadline |
| Goalscorer odds | SQLite | Pre-GW | Cron day before deadline |
| Tracked managers | SQLite | On-demand | User action + daily cron |
| League members | SQLite | On-demand | When league viewed |
| Live GW data | Cache (30s TTL) | Real-time | API request |
| Predictions | SQLite | Daily + pre-GW | Cron + after odds sync |
| Historical (3 seasons) | SQLite | Once | Initial setup |

### Cron Schedule

```bash
# Daily at 6am - refresh player stats
0 6 * * * php /path/to/api/cron/daily-sync.php

# 24h before GW deadline - fetch match odds (The Odds API)
0 12 * * 4,5 php /path/to/api/cron/odds-sync.php

# 24h before GW deadline - scrape goalscorer odds (Oddschecker)
0 13 * * 4,5 php /path/to/api/cron/goalscorer-odds-sync.php

# After odds sync - regenerate predictions
0 14 * * 4,5 php /path/to/api/cron/predictions.php

# Every hour on match days - update fixtures
0 * * * 0,6 php /path/to/api/cron/fixture-sync.php

# Live polling handled by frontend (60s interval via API)
```

### Rate Limits Strategy

**FPL API** (unofficial, no published limits):
- No authentication required
- Conservative approach: max 1 request/second
- Batch operations where possible (bootstrap-static has all players)
- Cache aggressively (player data changes daily, not per-request)
- User-triggered requests: debounce + cache

**The Odds API** (free tier: 500 requests/month):
- ~16 requests/day budget
- 1 request for all EPL match odds = ~10 fixtures
- Weekly goalscorer odds scrape (separate source)
- Cache odds until next sync

```php
class RateLimiter {
    private const FPL_MIN_INTERVAL_MS = 1000;
    private const ODDS_DAILY_LIMIT = 16;

    public function throttle(string $api): void {
        $lastRequest = $this->cache->get("{$api}_last_request");
        $elapsed = microtime(true) - $lastRequest;
        if ($elapsed < self::FPL_MIN_INTERVAL_MS / 1000) {
            usleep((self::FPL_MIN_INTERVAL_MS - $elapsed * 1000) * 1000);
        }
        $this->cache->set("{$api}_last_request", microtime(true));
    }
}
```

---

## Database Schema (SQLite)

```sql
-- Core reference data
CREATE TABLE clubs (
    id INTEGER PRIMARY KEY,
    name TEXT,
    short_name TEXT,
    strength_attack_home INTEGER,
    strength_attack_away INTEGER,
    strength_defence_home INTEGER,
    strength_defence_away INTEGER
);

CREATE TABLE players (
    id INTEGER PRIMARY KEY,
    code INTEGER,                -- Stable across seasons
    web_name TEXT,
    first_name TEXT,
    second_name TEXT,
    club_id INTEGER REFERENCES clubs(id),
    position INTEGER,            -- 1=GK, 2=DEF, 3=MID, 4=FWD
    now_cost INTEGER,
    total_points INTEGER,
    form REAL,
    selected_by_percent REAL,
    minutes INTEGER,
    goals_scored INTEGER,
    assists INTEGER,
    clean_sheets INTEGER,
    expected_goals REAL,
    expected_assists REAL,
    expected_goals_conceded REAL,
    ict_index REAL,
    bps INTEGER,                 -- Bonus point system raw score
    bonus INTEGER,               -- Actual bonus points earned
    defensive_contribution INTEGER,  -- NEW: tackles, blocks, etc.
    starts INTEGER,
    chance_of_playing INTEGER,
    news TEXT,
    updated_at TIMESTAMP
);

CREATE TABLE fixtures (
    id INTEGER PRIMARY KEY,
    gameweek INTEGER,
    home_club_id INTEGER REFERENCES clubs(id),
    away_club_id INTEGER REFERENCES clubs(id),
    kickoff_time TIMESTAMP,
    home_score INTEGER,
    away_score INTEGER,
    home_difficulty INTEGER,
    away_difficulty INTEGER,
    finished BOOLEAN
);

CREATE TABLE player_gameweek_history (
    player_id INTEGER REFERENCES players(id),
    gameweek INTEGER,
    fixture_id INTEGER REFERENCES fixtures(id),
    opponent_team INTEGER,
    was_home BOOLEAN,
    minutes INTEGER,
    goals_scored INTEGER,
    assists INTEGER,
    clean_sheets INTEGER,
    goals_conceded INTEGER,
    bonus INTEGER,
    bps INTEGER,
    defensive_contribution INTEGER,  -- NEW
    total_points INTEGER,
    expected_goals REAL,
    expected_assists REAL,
    expected_goals_conceded REAL,
    value INTEGER,
    selected INTEGER,
    PRIMARY KEY (player_id, gameweek)
);

-- Tracked managers
CREATE TABLE managers (
    id INTEGER PRIMARY KEY,
    name TEXT,
    team_name TEXT,
    overall_rank INTEGER,
    overall_points INTEGER,
    last_synced TIMESTAMP
);

CREATE TABLE manager_picks (
    manager_id INTEGER REFERENCES managers(id),
    gameweek INTEGER,
    player_id INTEGER REFERENCES players(id),
    position INTEGER,
    multiplier INTEGER, -- 0=bench, 1=playing, 2=captain, 3=TC
    is_captain BOOLEAN,
    is_vice_captain BOOLEAN,
    PRIMARY KEY (manager_id, gameweek, player_id)
);

CREATE TABLE manager_history (
    manager_id INTEGER REFERENCES managers(id),
    gameweek INTEGER,
    points INTEGER,
    total_points INTEGER,
    overall_rank INTEGER,
    bank INTEGER,
    team_value INTEGER,
    transfers_cost INTEGER,
    points_on_bench INTEGER,
    PRIMARY KEY (manager_id, gameweek)
);

-- Predictions (computed)
CREATE TABLE player_predictions (
    player_id INTEGER REFERENCES players(id),
    gameweek INTEGER,
    predicted_points REAL,
    confidence REAL,
    model_version TEXT,
    computed_at TIMESTAMP,
    PRIMARY KEY (player_id, gameweek)
);

-- Historical data (3 seasons)
CREATE TABLE seasons (
    id TEXT PRIMARY KEY,  -- e.g., "2023-24"
    start_date DATE,
    end_date DATE
);

CREATE TABLE player_season_history (
    player_code INTEGER,  -- Stable across seasons (element_code)
    season_id TEXT REFERENCES seasons(id),
    total_points INTEGER,
    minutes INTEGER,
    goals_scored INTEGER,
    assists INTEGER,
    clean_sheets INTEGER,
    expected_goals REAL,
    expected_assists REAL,
    start_cost INTEGER,
    end_cost INTEGER,
    PRIMARY KEY (player_code, season_id)
);

-- Odds data
CREATE TABLE fixture_odds (
    fixture_id INTEGER REFERENCES fixtures(id),
    home_win_prob REAL,  -- Converted from odds
    draw_prob REAL,
    away_win_prob REAL,
    home_cs_prob REAL,
    away_cs_prob REAL,
    expected_total_goals REAL,
    updated_at TIMESTAMP,
    PRIMARY KEY (fixture_id)
);

CREATE TABLE player_goalscorer_odds (
    player_id INTEGER REFERENCES players(id),
    fixture_id INTEGER REFERENCES fixtures(id),
    anytime_scorer_prob REAL,  -- Converted from odds
    updated_at TIMESTAMP,
    PRIMARY KEY (player_id, fixture_id)
);

-- Mini-league caching
CREATE TABLE leagues (
    id INTEGER PRIMARY KEY,
    name TEXT,
    type TEXT,  -- 'classic' or 'h2h'
    last_synced TIMESTAMP
);

CREATE TABLE league_members (
    league_id INTEGER REFERENCES leagues(id),
    manager_id INTEGER REFERENCES managers(id),
    rank INTEGER,
    PRIMARY KEY (league_id, manager_id)
);
```

---

## PHP Architecture (Monorepo with Extractable FPL Client)

```
super-fpl/
├── packages/
│   └── fpl-client/                    # STANDALONE PACKAGE (extractable)
│       ├── composer.json              # "superfpl/fpl-client"
│       ├── src/
│       │   ├── FplClient.php          # Main API client
│       │   ├── Endpoints/
│       │   │   ├── BootstrapEndpoint.php
│       │   │   ├── FixturesEndpoint.php
│       │   │   ├── EntryEndpoint.php
│       │   │   ├── LiveEndpoint.php
│       │   │   └── LeagueEndpoint.php
│       │   ├── Models/
│       │   │   ├── Player.php
│       │   │   ├── Team.php
│       │   │   ├── Fixture.php
│       │   │   ├── Entry.php
│       │   │   └── Gameweek.php
│       │   ├── Cache/
│       │   │   ├── CacheInterface.php
│       │   │   └── FileCache.php
│       │   └── RateLimiter.php
│       └── tests/
│
├── api/                               # SUPERFPL API (uses fpl-client)
│   ├── public/
│   │   └── index.php
│   ├── config/
│   │   ├── config.php
│   │   └── routes.php
│   ├── src/
│   │   ├── Clients/
│   │   │   ├── OddsApiClient.php      # The Odds API (match odds)
│   │   │   └── OddscheckerScraper.php # Oddschecker (goalscorer odds)
│   │   ├── Database.php               # SQLite PDO helper
│   │   ├── Sync/
│   │   │   ├── PlayerSync.php
│   │   │   ├── FixtureSync.php
│   │   │   ├── ManagerSync.php
│   │   │   ├── LeagueSync.php
│   │   │   ├── OddsSync.php
│   │   │   └── HistorySync.php
│   │   ├── Services/
│   │   │   ├── PlayerService.php
│   │   │   ├── ManagerService.php
│   │   │   ├── LeagueService.php
│   │   │   ├── PredictionService.php
│   │   │   ├── ComparisonService.php
│   │   │   └── LiveService.php
│   │   └── Prediction/
│   │       ├── PredictionEngine.php
│   │       ├── GoalProbability.php
│   │       ├── CleanSheetProbability.php
│   │       ├── BonusProbability.php
│   │       ├── DefensiveContributionProbability.php  # NEW
│   │       └── MinutesProbability.php
│   ├── cron/
│   │   ├── daily-sync.php
│   │   ├── odds-sync.php
│   │   ├── predictions.php
│   │   └── live-cache.php
│   ├── data/
│   │   └── superfpl.db
│   └── composer.json                  # requires "superfpl/fpl-client": "*"
│
├── frontend/                          # React app
│   └── ...
│
└── composer.json                      # Root workspace
```

### Standalone FPL Client Package

```php
// Usage as standalone package
use SuperFPL\FplClient\FplClient;

$client = new FplClient();

// Get all players
$bootstrap = $client->bootstrap()->get();
$players = $bootstrap->elements;

// Get manager's team
$entry = $client->entry(12345)->get();
$picks = $client->entry(12345)->picks(25); // GW 25

// Get live data
$live = $client->live(25)->get();
```

### Key API Endpoints

```
GET  /api/players                    # All players with filters
GET  /api/players/{id}               # Single player detail
GET  /api/players/{id}/history       # Player GW history
GET  /api/players/predictions/{gw}   # Predicted points for GW

GET  /api/managers/{id}              # Manager overview
GET  /api/managers/{id}/picks/{gw}   # Manager picks for GW
GET  /api/managers/{id}/history      # Season history
POST /api/managers/track             # Add manager to tracking

GET  /api/compare                    # Compare multiple managers
     ?ids=123,456,789
     &gw=25

GET  /api/live/{gw}                  # Live GW data (proxied)

GET  /api/fixtures                   # All fixtures
GET  /api/fixtures?gw=25             # Fixtures for GW
GET  /api/fdr/{club_id}              # Fixture difficulty ratings
```

---

## React Frontend Structure (Tailwind + shadcn/ui)

```
frontend/
├── package.json
├── tailwind.config.js
├── components.json           # shadcn/ui config
├── src/
│   ├── api/
│   │   └── client.ts         # API client with React Query
│   ├── hooks/
│   │   ├── usePlayers.ts
│   │   ├── useManager.ts
│   │   ├── usePredictions.ts
│   │   ├── useLive.ts
│   │   └── useLeague.ts
│   ├── components/
│   │   ├── ui/               # shadcn/ui components
│   │   │   ├── button.tsx
│   │   │   ├── card.tsx
│   │   │   ├── table.tsx
│   │   │   └── ...
│   │   ├── common/
│   │   │   ├── PlayerCard.tsx
│   │   │   ├── PositionBadge.tsx
│   │   │   ├── FixtureTicker.tsx
│   │   │   └── PlayerPhoto.tsx
│   │   ├── team-analyzer/
│   │   │   ├── SquadPitch.tsx    # Visual pitch layout
│   │   │   ├── SquadTable.tsx
│   │   │   ├── ValueAnalysis.tsx
│   │   │   └── FormChart.tsx
│   │   ├── predictor/
│   │   │   ├── PredictionTable.tsx
│   │   │   ├── PlayerComparison.tsx
│   │   │   └── FDRCalendar.tsx
│   │   ├── planner/
│   │   │   ├── TransferSuggestions.tsx
│   │   │   ├── FixturePlanner.tsx
│   │   │   └── ChipStrategy.tsx
│   │   ├── comparator/
│   │   │   ├── ManagerGrid.tsx
│   │   │   ├── OwnershipMatrix.tsx
│   │   │   ├── DifferentialsList.tsx
│   │   │   └── RiskMeter.tsx
│   │   └── live/
│   │       ├── LivePoints.tsx
│   │       ├── RankTracker.tsx
│   │       ├── BonusPredictor.tsx
│   │       └── AutoSubSimulator.tsx
│   ├── pages/
│   │   ├── Home.tsx
│   │   ├── TeamAnalyzer.tsx
│   │   ├── Predictor.tsx
│   │   ├── Planner.tsx
│   │   ├── Compare.tsx
│   │   ├── League.tsx
│   │   └── Live.tsx
│   ├── lib/
│   │   ├── utils.ts          # cn() helper for Tailwind
│   │   └── constants.ts      # FPL scoring rules, positions
│   └── types/
│       ├── player.ts
│       ├── manager.ts
│       ├── fixture.ts
│       └── prediction.ts
```

---

## Points Prediction Model (Hybrid Approach)

### Data Sources

**FPL API (Primary)**
- xG, xA, xGI per 90 minutes
- BPS (bonus point system) raw scores
- xGC (expected goals conceded)
- ICT index (influence, creativity, threat)
- Club strength ratings
- Form (30-day rolling)
- Minutes/starts history
- Chance of playing %

**Bookmaker Odds (Secondary)**
- Match result probabilities (1X2) → Clean sheet probability
- Anytime goalscorer odds → Goal probability per player
- Clean sheet odds → Direct CS probability
- Over/under goals → Match goal expectation

### FPL Scoring Rules (2025/26 Season)

| Action | GKP | DEF | MID | FWD |
|--------|-----|-----|-----|-----|
| Playing 60+ mins | 2 | 2 | 2 | 2 |
| Playing 1-59 mins | 1 | 1 | 1 | 1 |
| Goal scored | 6 | 6 | 5 | 4 |
| Assist | 3 | 3 | 3 | 3 |
| Clean sheet | 4 | 4 | 1 | 0 |
| 3 saves | 1 | - | - | - |
| Penalty save | 5 | - | - | - |
| Penalty miss | -2 | -2 | -2 | -2 |
| 2 goals conceded | -1 | -1 | -1 | - |
| Yellow card | -1 | -1 | -1 | -1 |
| Red card | -3 | -3 | -3 | -3 |
| Own goal | -2 | -2 | -2 | -2 |
| Bonus (1-3) | 1-3 | 1-3 | 1-3 | 1-3 |
| Defensive contributions | - | 10 = 2pts | 12 = 2pts | 12 = 2pts |

**Defensive Contributions (NEW 2025/26)**: Tackles, interceptions, clearances, blocks, recoveries. Calculated per match. Available in FPL API as `defensive_contribution` field.

### Model Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    HYBRID PREDICTION MODEL                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐       │
│  │  FPL Data   │     │ Bookmaker   │     │ Historical  │       │
│  │  (xG/xA/BPS)│     │    Odds     │     │   3 seasons │       │
│  └──────┬──────┘     └──────┬──────┘     └──────┬──────┘       │
│         │                   │                   │               │
│         ▼                   ▼                   ▼               │
│  ┌────────────────────────────────────────────────────┐        │
│  │              RULE-BASED CORE                        │        │
│  │  • Minutes probability (starts history + news)      │        │
│  │  • Goal probability (xG × odds adjustment)          │        │
│  │  • Assist probability (xA × odds adjustment)        │        │
│  │  • Clean sheet probability (odds-derived)           │        │
│  │  • Bonus probability (BPS history by position)      │        │
│  └────────────────────────────────────────────────────┘        │
│                            │                                    │
│                            ▼                                    │
│  ┌────────────────────────────────────────────────────┐        │
│  │           LEARNED ADJUSTMENTS                       │        │
│  │  • Position-specific multipliers                    │        │
│  │  • Home/away factors                                │        │
│  │  • FDR correlation weights                          │        │
│  │  • Form decay rates                                 │        │
│  │  Trained on 3 seasons of historical data            │        │
│  └────────────────────────────────────────────────────┘        │
│                            │                                    │
│                            ▼                                    │
│  ┌────────────────────────────────────────────────────┐        │
│  │           EXPECTED POINTS OUTPUT                    │        │
│  │  E[pts] = Σ P(action) × points(action)              │        │
│  │  + confidence interval                              │        │
│  └────────────────────────────────────────────────────┘        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Prediction Formula

```php
class PredictionService {
    public function predictPoints(Player $player, Fixture $fixture): Prediction {
        // 1. Minutes probability
        $minProb = $this->getMinutesProbability($player);
        $full90Prob = $minProb * $player->starts_per_90;

        // 2. Goal probability (combine xG with bookmaker odds)
        $xgPerMatch = $player->expected_goals_per_90 * ($full90Prob * 90 / 90);
        $oddsGoalProb = $this->getAnytimeScorerProb($player, $fixture);
        $goalProb = ($xgPerMatch * 0.6) + ($oddsGoalProb * 0.4); // Blend

        // 3. Assist probability
        $xaPerMatch = $player->expected_assists_per_90 * $full90Prob;
        $assistProb = $xaPerMatch * $this->getLearnedAssistFactor($player->position);

        // 4. Clean sheet probability (from odds)
        $csProb = $this->getCleanSheetProb($fixture, $player->club_id);

        // 5. Bonus probability (from BPS history)
        $bonusExp = $this->getExpectedBonus($player);

        // 6. Calculate expected points
        $points = 0;
        $points += $full90Prob * 2 + (1 - $full90Prob) * $minProb * 1; // Appearance
        $points += $goalProb * $this->getGoalPoints($player->position);
        $points += $assistProb * 3;
        $points += $csProb * $this->getCSPoints($player->position) * $full90Prob;
        $points += $bonusExp;

        // 7. Apply learned adjustments
        $points *= $this->getHomeAwayFactor($fixture, $player->club_id);
        $points *= $this->getFormAdjustment($player);

        return new Prediction($player->id, $fixture->gameweek, $points);
    }
}
```

### Odds Sources

| Source | Data | Frequency | Access |
|--------|------|-----------|--------|
| The Odds API | 1X2, O/U, CS odds | 24h before GW | API (500 req/month free) |
| Oddschecker | Anytime goalscorer | 24h before GW | Scrape (respectful) |

---

## Manager Comparison Metrics

### Effective Ownership (EO)

```typescript
// In a group of managers, what % "own" each player weighted by captaincy
function calculateEO(managers: Manager[], playerId: number) {
  let eo = 0;
  for (const manager of managers) {
    const pick = manager.picks.find(p => p.player_id === playerId);
    if (pick) {
      eo += pick.multiplier; // 0, 1, 2, or 3
    }
  }
  return (eo / managers.length) * 100;
}
```

### Relative Risk Score

```typescript
// How differentiated is a manager's team from the group?
function calculateRiskScore(manager: Manager, group: Manager[]) {
  const groupEO = calculateGroupEO(group);
  let riskScore = 0;

  for (const pick of manager.picks.filter(p => p.multiplier > 0)) {
    const playerEO = groupEO[pick.player_id] || 0;

    // Low EO players = high risk/reward
    if (pick.is_captain) {
      riskScore += (100 - playerEO) * 2;
    } else {
      riskScore += (100 - playerEO);
    }
  }

  return riskScore / 11; // Normalize by playing XI
}
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1) ✅ DONE

- [x] Set up project structure (monorepo: api/ + frontend/)
- [x] Create SQLite schema with all tables
- [x] Build FPL API client (PHP)
- [x] Implement core sync: players, fixtures, clubs
- [x] Basic API endpoints: GET /players, GET /fixtures
- [x] React app scaffold with Tailwind + shadcn/ui
- [x] Verify: curl API, see players in React table

### Phase 2: Team Analyzer (Week 2) ✅ DONE

- [x] Manager lookup by ID (FPL API proxy)
- [x] Manager tracking (save to DB)
- [x] Squad display with pitch visualization
- [x] Player cards with stats
- [ ] GW history chart (optional enhancement)
- [x] Verify: enter manager ID, see full squad analysis

### Phase 3: Predictions Core (Week 3) ✅ DONE

- [ ] Historical data sync (3 seasons from vaastav repo) - deferred to Phase 4
- [x] Odds integration (Oddschecker scraper)
- [x] Odds probability conversion
- [x] Rule-based prediction engine (MinutesProbability, GoalProbability, CleanSheetProbability, BonusProbability)
- [x] Prediction API: GET /predictions/{gw}
- [x] Prediction table UI with sorting
- [x] OddsSync and predictions cron jobs
- [ ] Verify: predictions match reasonable expectations (needs live data)

### Phase 4: Prediction Refinement (Week 4)

- [ ] BPS/bonus probability model
- [ ] Minutes probability model
- [ ] Learned adjustment factors (train on history)
- [ ] Multi-GW predictions (next 5 GWs)
- [ ] FDR calendar view
- [ ] Verify: backtest against completed GWs

### Phase 5: Mini-League & Comparison (Week 5) ✅ DONE

- [x] League lookup and auto-cache members
- [x] Effective ownership calculation
- [x] Ownership matrix visualization
- [x] Differential finder
- [x] Risk score calculation
- [ ] Verify: compare 2+ managers in same league (needs live data)

### Phase 6: Live Features (Week 6) ✅ DONE

- [x] Live GW data proxy with caching (LiveService with 60s TTL)
- [x] Real-time points calculation (getManagerLivePoints)
- [ ] Rank movement tracker (deferred - needs more API data)
- [x] Bonus point predictor (live BPS)
- [ ] Auto-sub simulator (deferred - complex logic)
- [ ] Verify: test during actual match day

### Phase 7: Transfer Planner (Week 7) ✅ DONE

- [x] Transfer suggestions based on predictions
- [x] Budget-aware recommendations
- [ ] Chip strategy advisor (deferred - needs more logic)
- [x] "What if" simulator (transfer simulation endpoint)
- [ ] Verify: reasonable transfer suggestions (needs live data)

---

## Repository & Deployment

**Repository**: github.com/dmallory42/super-fpl (replacing existing Flask app)

**Hosting**: DigitalOcean Droplet → superfpl.com

```
┌─────────────────────────────────────────────────────┐
│                  DigitalOcean Droplet               │
│                                                     │
│  ┌─────────────┐    ┌─────────────────────────────┐ │
│  │   Nginx     │───▶│  PHP-FPM (api/)             │ │
│  │  (proxy)    │    │  └── superfpl.db            │ │
│  │             │    └─────────────────────────────┘ │
│  │             │                                    │
│  │  /api/*  ───┼──▶ PHP                            │
│  │  /*      ───┼──▶ frontend/dist/ (static)        │
│  └─────────────┘                                    │
│                                                     │
│  ┌─────────────────────────────────────────────────┐ │
│  │  Cron (via supervisor or systemd)               │ │
│  │  - daily-sync.php (6am)                         │ │
│  │  - odds-sync.php (before GW deadline)           │ │
│  │  - predictions.php (after odds)                 │ │
│  └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘

Domain: superfpl.com → Droplet IP
SSL: Let's Encrypt (Certbot)
```

### Local Development

```bash
# Start all services
docker-compose up -d

# Access:
# - Frontend: http://localhost:5173 (Vite dev server)
# - API: http://localhost:8080/api
# - PHP runs via Docker PHP-FPM + Nginx
```

---

## Testing Strategy

### PHP Unit Tests (PHPUnit)

```
packages/fpl-client/tests/
├── Unit/
│   ├── FplClientTest.php           # API client methods
│   ├── RateLimiterTest.php         # Rate limiting logic
│   └── Models/
│       ├── PlayerTest.php          # Model hydration
│       └── FixtureTest.php
└── Integration/
    └── BootstrapEndpointTest.php   # Real API calls (sparingly)

api/tests/
├── Unit/
│   ├── Services/
│   │   ├── PredictionServiceTest.php
│   │   ├── ComparisonServiceTest.php
│   │   └── PlayerServiceTest.php
│   ├── Prediction/
│   │   ├── GoalProbabilityTest.php
│   │   ├── CleanSheetProbabilityTest.php
│   │   └── BonusProbabilityTest.php
│   └── Sync/
│       └── PlayerSyncTest.php
└── Feature/
    ├── PlayerApiTest.php           # Full endpoint tests
    ├── ManagerApiTest.php
    └── PredictionApiTest.php
```

Run: `composer test` (configured in phpunit.xml)

### Frontend Tests

**Vitest (Unit/Component)**:
```
frontend/src/
├── components/
│   ├── __tests__/
│   │   ├── PlayerCard.test.tsx
│   │   ├── SquadPitch.test.tsx
│   │   └── PredictionTable.test.tsx
├── hooks/
│   └── __tests__/
│       ├── usePlayers.test.ts
│       └── useManager.test.ts
└── lib/
    └── __tests__/
        └── points.test.ts          # Points calculation logic
```

**Playwright (E2E)**:
```
frontend/e2e/
├── team-analyzer.spec.ts    # Enter ID → see squad
├── predictor.spec.ts        # View predictions → sort/filter
├── compare.spec.ts          # Add managers → see comparison
├── live.spec.ts             # Mock live data → verify updates
└── fixtures/
    └── mock-api.ts          # API mocking helpers
```

Run:
- Unit: `npm test`
- E2E: `npm run test:e2e`

### CI Pipeline (GitHub Actions)

```yaml
# .github/workflows/test.yml
name: Tests
on: [push, pull_request]
jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2' }
      - run: composer install
      - run: composer test

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: cd frontend && npm ci
      - run: cd frontend && npm test
      - run: cd frontend && npx playwright install --with-deps
      - run: cd frontend && npm run test:e2e
```

---

## Verification Plan

1. **API Layer**: Run PHPUnit tests, curl endpoints manually
2. **Sync Jobs**: Integration tests with mock API responses
3. **Frontend**: Vitest component tests + Playwright E2E
4. **Predictions**: Unit tests with known inputs → expected outputs
5. **Live Features**: Playwright E2E with mocked live data + real match day smoke test
