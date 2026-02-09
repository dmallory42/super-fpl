#!/usr/bin/env php
<?php

/**
 * Show xPoints breakdown for a player.
 *
 * Usage:
 *   php xpts-breakdown.php <player_name_or_id> [gameweek]
 *
 * Examples:
 *   php xpts-breakdown.php Salah 27
 *   php xpts-breakdown.php "De Bruyne"
 *   php xpts-breakdown.php 328
 */

declare(strict_types=1);

require __DIR__ . '/../cron/bootstrap.php';

use SuperFPL\Api\Prediction\MinutesProbability;
use SuperFPL\Api\Services\PredictionService;

if ($argc < 2) {
    echo "Usage: php xpts-breakdown.php <player_name_or_id> [gameweek]\n";
    echo "  player_name_or_id  Player web_name (fuzzy) or numeric ID\n";
    echo "  gameweek           Gameweek number (default: next unfinished)\n";
    exit(1);
}

$query = $argv[1];
$gameweek = isset($argv[2]) ? (int) $argv[2] : null;

// --- Resolve player ---
if (ctype_digit($query)) {
    $player = $db->fetchOne('SELECT * FROM players WHERE id = ?', [(int) $query]);
    if (!$player) {
        echo "No player found with ID {$query}\n";
        exit(1);
    }
} else {
    // Fuzzy name search: exact match first, then LIKE
    $player = $db->fetchOne(
        'SELECT * FROM players WHERE LOWER(web_name) = LOWER(?)',
        [$query]
    );
    if (!$player) {
        $player = $db->fetchOne(
            'SELECT * FROM players WHERE LOWER(web_name) LIKE LOWER(?) ORDER BY total_points DESC LIMIT 1',
            ['%' . $query . '%']
        );
    }
    if (!$player) {
        echo "No player found matching \"{$query}\"\n";
        // Show closest matches
        $matches = $db->fetchAll(
            'SELECT id, web_name, club_id FROM players WHERE LOWER(web_name) LIKE LOWER(?) ORDER BY total_points DESC LIMIT 5',
            ['%' . substr($query, 0, 3) . '%']
        );
        if (!empty($matches)) {
            echo "Did you mean:\n";
            foreach ($matches as $m) {
                echo "  - {$m['web_name']} (ID: {$m['id']})\n";
            }
        }
        exit(1);
    }
}

// --- Resolve gameweek ---
if ($gameweek === null) {
    $fixture = $db->fetchOne(
        "SELECT gameweek FROM fixtures WHERE finished = 0 ORDER BY kickoff_time ASC LIMIT 1"
    );
    $gameweek = $fixture ? (int) $fixture['gameweek'] : 1;
}

// --- Team name ---
$team = $db->fetchOne('SELECT short_name FROM clubs WHERE id = ?', [(int) $player['club_id']]);
$teamName = $team['short_name'] ?? '???';

// --- Position label ---
$posLabels = [1 => 'GKP', 2 => 'DEF', 3 => 'MID', 4 => 'FWD'];
$posLabel = $posLabels[(int) $player['position']] ?? '???';

// --- Minutes probability ---
$minutesProb = new MinutesProbability();
$teamGames = $db->fetchOne(
    "SELECT COUNT(*) as games FROM (
        SELECT home_club_id as club_id FROM fixtures WHERE finished = 1
        UNION ALL
        SELECT away_club_id as club_id FROM fixtures WHERE finished = 1
    ) WHERE club_id = ?",
    [(int) $player['club_id']]
);
$tg = $teamGames ? (int) $teamGames['games'] : 24;
$minutes = $minutesProb->calculate($player, $tg);

// --- Prediction breakdown ---
$service = new PredictionService($db);
$prediction = $service->getPlayerPrediction((int) $player['id'], $gameweek);

// --- Fixture info ---
$fixtures = $db->fetchAll(
    'SELECT f.*, ht.short_name as home_team, at.short_name as away_team
     FROM fixtures f
     JOIN clubs ht ON f.home_club_id = ht.id
     JOIN clubs at ON f.away_club_id = at.id
     WHERE f.gameweek = ? AND (f.home_club_id = ? OR f.away_club_id = ?)',
    [$gameweek, (int) $player['club_id'], (int) $player['club_id']]
);

// --- Cached per_90 ---
$cached = $db->fetchOne(
    'SELECT predicted_per_90 FROM player_predictions WHERE player_id = ? AND gameweek = ?',
    [(int) $player['id'], $gameweek]
);
$per90 = $cached ? (float) $cached['predicted_per_90'] : null;

// --- Output ---
$w = 52; // total width
$line = str_repeat('─', $w);

echo "\n┌{$line}┐\n";
printf("│ %-{$w}s│\n", "  {$player['web_name']} ({$posLabel}, {$teamName})");
printf("│ %-{$w}s│\n", "  ID: {$player['id']}  £" . number_format((float) $player['now_cost'] / 10, 1) . "m  Form: {$player['form']}  Pts: {$player['total_points']}");
echo "├{$line}┤\n";

// Fixtures
if (empty($fixtures)) {
    printf("│ %-{$w}s│\n", "  GW{$gameweek}: No fixture (blank)");
} else {
    foreach ($fixtures as $fx) {
        $isHome = (int) $fx['home_club_id'] === (int) $player['club_id'];
        $opp = $isHome ? $fx['away_team'] : $fx['home_team'];
        $venue = $isHome ? 'H' : 'A';
        printf("│ %-{$w}s│\n", "  GW{$gameweek}: vs {$opp} ({$venue})");
    }
}

echo "├{$line}┤\n";
printf("│ %-{$w}s│\n", '  MINUTES MODEL');
echo "│{$line}│\n";
printf("│  %-28s %20s  │\n", 'prob_any (plays at all)', number_format($minutes['prob_any'] * 100, 1) . '%');
printf("│  %-28s %20s  │\n", 'prob_60 (plays 60+ mins)', number_format($minutes['prob_60'] * 100, 1) . '%');
printf("│  %-28s %20s  │\n", 'expected_mins', number_format($minutes['expected_mins'], 1));
printf("│  %-28s %20s  │\n", 'minutesFraction (xM/90)', number_format($minutes['expected_mins'] / 90, 3));

$cop = $player['chance_of_playing'];
$news = $player['news'] ?? '';
if ($cop !== null || !empty($news)) {
    printf("│  %-28s %20s  │\n", 'chance_of_playing', $cop !== null ? "{$cop}%" : 'n/a');
    if (!empty($news)) {
        $truncNews = mb_strlen($news) > 48 ? mb_substr($news, 0, 45) . '...' : $news;
        printf("│  %-50s  │\n", $truncNews);
    }
}

echo "├{$line}┤\n";
printf("│ %-{$w}s│\n", '  POINTS BREAKDOWN  (GW' . $gameweek . ')');
echo "│{$line}│\n";

if ($prediction && !empty($prediction['breakdown'])) {
    $breakdown = $prediction['breakdown'];

    $components = [
        'appearance'              => 'Appearance',
        'goals'                   => 'Goals',
        'assists'                 => 'Assists',
        'clean_sheet'             => 'Clean Sheet',
        'bonus'                   => 'Bonus',
        'saves'                   => 'Saves',
        'goals_conceded'          => 'Goals Conceded',
        'defensive_contribution'  => 'Defensive Contrib',
        'cards'                   => 'Cards/OG/PenMiss',
        'penalties'               => 'Penalties',
    ];

    foreach ($components as $key => $label) {
        $value = $breakdown[$key] ?? 0;
        if (abs($value) < 0.005 && !in_array($key, ['appearance', 'goals', 'assists', 'clean_sheet', 'bonus'])) {
            continue; // Skip zero-value minor components
        }
        $sign = $value >= 0 ? '+' : '';
        printf("│  %-28s %20s  │\n", $label, $sign . number_format($value, 2));
    }

    echo "│{$line}│\n";
    printf("│  %-28s %20s  │\n", 'TOTAL (predicted_points)', number_format($prediction['predicted_points'], 2));
    if ($per90 !== null) {
        printf("│  %-28s %20s  │\n", 'PER 90 (predicted_per_90)', number_format($per90, 2));
    }
    printf("│  %-28s %20s  │\n", 'Confidence', number_format($prediction['confidence'] * 100, 0) . '%');
    printf("│  %-28s %20s  │\n", 'Fixtures', (string) $prediction['fixture_count']);
} else {
    printf("│  %-{$w}s│\n", '  No prediction data available');
}

echo "└{$line}┘\n\n";
