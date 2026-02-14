CREATE INDEX IF NOT EXISTS idx_manager_picks_mgr_gw ON manager_picks(manager_id, gameweek);
CREATE INDEX IF NOT EXISTS idx_player_history_pid_gw ON player_gameweek_history(player_id, gameweek);
CREATE INDEX IF NOT EXISTS idx_player_predictions_pid_gw ON player_predictions(player_id, gameweek);
CREATE INDEX IF NOT EXISTS idx_prediction_snapshots_pid_gw ON prediction_snapshots(player_id, gameweek);
CREATE INDEX IF NOT EXISTS idx_goalscorer_odds_pid_fid ON player_goalscorer_odds(player_id, fixture_id);
CREATE INDEX IF NOT EXISTS idx_assist_odds_pid_fid ON player_assist_odds(player_id, fixture_id);
