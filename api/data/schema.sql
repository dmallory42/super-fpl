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
    code INTEGER,
    web_name TEXT,
    first_name TEXT,
    second_name TEXT,
    club_id INTEGER REFERENCES clubs(id),
    position INTEGER,
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
    bps INTEGER,
    bonus INTEGER,
    starts INTEGER,
    chance_of_playing INTEGER,
    news TEXT,
    penalties_order INTEGER DEFAULT 0,
    penalties_taken INTEGER DEFAULT 0,
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
    multiplier INTEGER,
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

-- Predictions
CREATE TABLE player_predictions (
    player_id INTEGER REFERENCES players(id),
    gameweek INTEGER,
    predicted_points REAL,
    confidence REAL,
    model_version TEXT,
    computed_at TIMESTAMP,
    PRIMARY KEY (player_id, gameweek)
);

-- Historical data
CREATE TABLE seasons (
    id TEXT PRIMARY KEY,
    start_date DATE,
    end_date DATE
);

CREATE TABLE player_season_history (
    player_code INTEGER,
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
    home_win_prob REAL,
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
    anytime_scorer_prob REAL,
    updated_at TIMESTAMP,
    PRIMARY KEY (player_id, fixture_id)
);

-- Mini-league caching
CREATE TABLE leagues (
    id INTEGER PRIMARY KEY,
    name TEXT,
    type TEXT,
    last_synced TIMESTAMP
);

CREATE TABLE league_members (
    league_id INTEGER REFERENCES leagues(id),
    manager_id INTEGER REFERENCES managers(id),
    rank INTEGER,
    PRIMARY KEY (league_id, manager_id)
);

-- Sample picks for EO calculations
CREATE TABLE sample_picks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gameweek INTEGER NOT NULL,
    tier TEXT NOT NULL,  -- 'top_10k', 'top_100k', 'top_1m', 'overall'
    manager_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    multiplier INTEGER NOT NULL DEFAULT 1,  -- 1=normal, 2=captain, 3=triple_captain
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(gameweek, tier, manager_id, player_id)
);

CREATE INDEX idx_sample_picks_gw_tier ON sample_picks(gameweek, tier);

-- Indexes for common queries
CREATE INDEX idx_players_club ON players(club_id);
CREATE INDEX idx_players_position ON players(position);
CREATE INDEX idx_fixtures_gameweek ON fixtures(gameweek);
CREATE INDEX idx_player_history_gw ON player_gameweek_history(gameweek);
CREATE INDEX idx_manager_picks_gw ON manager_picks(gameweek);
