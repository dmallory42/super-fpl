<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class TransferOptimizerService
{
    private const PLANNING_HORIZON = 6; // Gameweeks to look ahead
    private const HIT_COST = 4; // Points cost per extra transfer
    private const HIT_THRESHOLD = 6; // Minimum net gain required to recommend a hit

    public function __construct(
        private Database $db,
        private FplClient $fplClient,
        private PredictionService $predictionService,
        private GameweekService $gameweekService,
    ) {
    }

    /**
     * Generate optimal transfer plan for a manager
     *
     * @param int $managerId Manager ID
     * @param array $chipPlan Optional fixed chip weeks ['wildcard' => 34, 'bench_boost' => 37]
     * @param int $freeTransfers Number of free transfers available
     * @param array $xMinsOverrides Player ID => expected minutes overrides
     */
    public function getOptimalPlan(
        int $managerId,
        array $chipPlan = [],
        int $freeTransfers = 1,
        array $xMinsOverrides = []
    ): array {
        $currentGw = $this->gameweekService->getCurrentGameweek();
        $upcomingGws = $this->gameweekService->getUpcomingGameweeks(self::PLANNING_HORIZON);
        $fixtureCounts = $this->gameweekService->getFixtureCounts($upcomingGws);

        // Get manager's current squad
        $managerData = $this->fplClient->entry($managerId)->getRaw();
        $picks = $this->fplClient->entry($managerId)->picks(max(1, $currentGw - 1));

        $currentSquad = array_column($picks['picks'] ?? [], 'element');
        $bank = ($managerData['last_deadline_bank'] ?? 0) / 10;
        $squadValue = ($managerData['last_deadline_value'] ?? 1000) / 10;

        // Get player data with predictions for all upcoming GWs
        $players = $this->db->fetchAll("SELECT * FROM players");
        $playerMap = [];
        foreach ($players as $p) {
            $playerMap[(int)$p['id']] = $p;
        }

        // Get predictions for upcoming gameweeks
        $predictions = [];
        foreach ($upcomingGws as $gw) {
            $gwPredictions = $this->predictionService->getPredictions($gw);
            foreach ($gwPredictions['predictions'] ?? $gwPredictions as $pred) {
                $playerId = (int) $pred['player_id'];
                if (!isset($predictions[$playerId])) {
                    $predictions[$playerId] = [];
                }
                $predictions[$playerId][$gw] = (float) ($pred['predicted_points'] ?? 0);
            }
        }

        // Model injury recovery for squad players
        // Their predictions should ramp up over the planning horizon as they recover
        foreach ($currentSquad as $playerId) {
            $player = $playerMap[$playerId] ?? null;
            if (!$player || !isset($predictions[$playerId])) {
                continue;
            }

            $chanceOfPlaying = $player['chance_of_playing'] ?? null;
            $news = $player['news'] ?? '';

            // Skip if fully fit (null/100)
            if ($chanceOfPlaying === null || $chanceOfPlaying >= 100) {
                continue;
            }

            // Try to parse expected return date from news (e.g., "Expected back 21 Feb")
            $recoveryWeeks = $this->estimateRecoveryWeeks($chanceOfPlaying, $news);

            // If player has no return date and chance = 0, they might be long-term injured
            // In this case, keep predictions at 0 for the full horizon
            if ($recoveryWeeks === null) {
                continue;
            }

            // Scale predictions based on expected recovery timeline
            $gwIndex = 0;
            foreach ($predictions[$playerId] as $gw => &$points) {
                if ($gwIndex < $recoveryWeeks) {
                    // Still injured - scale down heavily
                    // First week(s): near 0, gradually increasing
                    $availability = max(0, ($gwIndex / $recoveryWeeks) * 0.5);
                    $points = round($points * $availability, 2);
                }
                // After recovery weeks, use full prediction
                $gwIndex++;
            }
            unset($points);
        }

        // Apply xMins overrides to predictions for squad players
        // If user sets a player to 0 mins (injured), their predictions should be scaled to ~0
        // User overrides take precedence over automatic injury recovery modeling
        if (!empty($xMinsOverrides)) {
            foreach ($xMinsOverrides as $playerId => $xMins) {
                $playerId = (int) $playerId;
                if (!isset($predictions[$playerId])) {
                    continue;
                }

                // Get player's baseline expected mins (when fit)
                $player = $playerMap[$playerId] ?? null;
                $baseXMins = $player ? $this->calculateExpectedMins($player, true) : 80;

                // Scale predictions proportionally
                // e.g., if base is 80 mins and user sets 0, multiply by 0
                // if base is 80 mins and user sets 40, multiply by 0.5
                $scaleFactor = $baseXMins > 0 ? ($xMins / $baseXMins) : 0;
                $scaleFactor = max(0, min(1.5, $scaleFactor)); // Cap at 1.5x

                foreach ($predictions[$playerId] as $gw => &$points) {
                    $points = round($points * $scaleFactor, 2);
                }
                unset($points);
            }
        }

        // Identify DGW opportunities (teams with 2 fixtures)
        $dgwTeams = [];
        foreach ($fixtureCounts as $gw => $teams) {
            foreach ($teams as $teamId => $count) {
                if ($count >= 2) {
                    $dgwTeams[$gw][] = $teamId;
                }
            }
        }

        // Calculate current squad's predicted points
        $currentSquadPredictions = $this->calculateSquadPredictions(
            $currentSquad,
            $predictions,
            $upcomingGws,
            $playerMap
        );

        // Find recommended transfers
        $recommendations = $this->findOptimalTransfers(
            $currentSquad,
            $predictions,
            $upcomingGws,
            $playerMap,
            $bank,
            $freeTransfers,
            $chipPlan,
            $dgwTeams
        );

        // Suggest chip timing if not fixed
        $chipSuggestions = $this->suggestChipTiming(
            $currentSquad,
            $predictions,
            $upcomingGws,
            $playerMap,
            $dgwTeams,
            $chipPlan
        );

        // Build formation data for all gameweeks in planning horizon
        $formations = [];
        foreach ($upcomingGws as $gw) {
            $formations[$gw] = $this->buildFormationData($currentSquad, $predictions, $gw, $playerMap);
        }

        return [
            'current_gameweek' => $currentGw,
            'planning_horizon' => $upcomingGws,
            'current_squad' => [
                'player_ids' => $currentSquad,
                'bank' => $bank,
                'squad_value' => $squadValue,
                'free_transfers' => $freeTransfers,
                'predicted_points' => $currentSquadPredictions,
                'formations' => $formations,
            ],
            'dgw_teams' => $dgwTeams,
            'recommendations' => $recommendations,
            'chip_suggestions' => $chipSuggestions,
            'chip_plan' => $chipPlan,
        ];
    }

    private function calculateSquadPredictions(
        array $squadIds,
        array $predictions,
        array $gameweeks,
        array $playerMap
    ): array {
        $result = [];

        foreach ($gameweeks as $gw) {
            // Group players by position with their predictions
            $byPosition = [1 => [], 2 => [], 3 => [], 4 => []];

            foreach ($squadIds as $playerId) {
                $player = $playerMap[$playerId] ?? null;
                if (!$player) {
                    continue;
                }
                $position = (int) $player['position'];
                $pred = $predictions[$playerId][$gw] ?? 0;
                $byPosition[$position][] = ['id' => $playerId, 'pred' => $pred];
            }

            // Sort each position by predicted points descending
            foreach ($byPosition as $pos => &$players) {
                usort($players, fn($a, $b) => $b['pred'] <=> $a['pred']);
            }

            // Select optimal starting 11 (1 GK, 3-5 DEF, 2-5 MID, 1-3 FWD)
            $starting11 = $this->selectOptimalStarting11($byPosition);

            // Find captain (highest predicted points in starting 11)
            $captainPred = 0;
            foreach ($starting11 as $player) {
                if ($player['pred'] > $captainPred) {
                    $captainPred = $player['pred'];
                }
            }

            // Sum starting 11 points + captain bonus (captain counts twice)
            $gwTotal = 0;
            foreach ($starting11 as $player) {
                $gwTotal += $player['pred'];
            }
            $gwTotal += $captainPred; // Add captain bonus

            $result[$gw] = round($gwTotal, 1);
        }

        $result['total'] = round(array_sum(array_filter($result, fn($k) => $k !== 'total', ARRAY_FILTER_USE_KEY)), 1);
        return $result;
    }

    /**
     * Select optimal starting 11 from players grouped by position.
     * Formation constraints: 1 GK, 3-5 DEF, 2-5 MID, 1-3 FWD, total 11
     */
    private function selectOptimalStarting11(array $byPosition): array
    {
        $starting = [];

        // Must have 1 GK
        if (!empty($byPosition[1])) {
            $starting[] = $byPosition[1][0];
        }

        // Get top players from each position
        $defs = array_slice($byPosition[2], 0, 5);
        $mids = array_slice($byPosition[3], 0, 5);
        $fwds = array_slice($byPosition[4], 0, 3);

        // Must have minimum: 3 DEF, 2 MID, 1 FWD
        $selectedDefs = array_slice($defs, 0, 3);
        $selectedMids = array_slice($mids, 0, 2);
        $selectedFwds = array_slice($fwds, 0, 1);

        // Remaining slots (11 - 1 GK - 3 DEF - 2 MID - 1 FWD = 4 flex spots)
        $flexSpots = 4;

        // Pool remaining players for flex spots
        $flexPool = array_merge(
            array_slice($defs, 3),  // DEF 4-5
            array_slice($mids, 2),  // MID 3-5
            array_slice($fwds, 1)   // FWD 2-3
        );

        // Sort flex pool by predicted points
        usort($flexPool, fn($a, $b) => $b['pred'] <=> $a['pred']);

        // Fill flex spots with highest predicted, respecting max limits
        $defCount = 3;
        $midCount = 2;
        $fwdCount = 1;

        foreach ($flexPool as $player) {
            if ($flexSpots <= 0) {
                break;
            }

            $pos = null;
            foreach ($byPosition as $position => $players) {
                foreach ($players as $p) {
                    if ($p['id'] === $player['id']) {
                        $pos = $position;
                        break 2;
                    }
                }
            }

            // Check position limits
            if ($pos === 2 && $defCount < 5) {
                $selectedDefs[] = $player;
                $defCount++;
                $flexSpots--;
            } elseif ($pos === 3 && $midCount < 5) {
                $selectedMids[] = $player;
                $midCount++;
                $flexSpots--;
            } elseif ($pos === 4 && $fwdCount < 3) {
                $selectedFwds[] = $player;
                $fwdCount++;
                $flexSpots--;
            }
        }

        return array_merge($starting, $selectedDefs, $selectedMids, $selectedFwds);
    }

    /**
     * Build formation data with player details for display.
     */
    private function buildFormationData(
        array $squadIds,
        array $predictions,
        int $gameweek,
        array $playerMap
    ): array {
        // Group players by position with their predictions
        $byPosition = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ($squadIds as $playerId) {
            $player = $playerMap[$playerId] ?? null;
            if (!$player) {
                continue;
            }
            $position = (int) $player['position'];
            $pred = $predictions[$playerId][$gameweek] ?? 0;
            $byPosition[$position][] = [
                'id' => $playerId,
                'pred' => $pred,
                'player' => $player,
            ];
        }

        // Sort each position by predicted points descending
        foreach ($byPosition as $pos => &$players) {
            usort($players, fn($a, $b) => $b['pred'] <=> $a['pred']);
        }

        // Select optimal starting 11
        $starting11 = $this->selectOptimalStarting11($byPosition);
        $starting11Ids = array_column($starting11, 'id');

        // Find captain (highest predicted points in starting 11)
        $captainId = null;
        $captainPred = 0;
        foreach ($starting11 as $player) {
            if ($player['pred'] > $captainPred) {
                $captainPred = $player['pred'];
                $captainId = $player['id'];
            }
        }

        // Find vice captain (second highest)
        $viceCaptainId = null;
        $viceCaptainPred = 0;
        foreach ($starting11 as $player) {
            if ($player['id'] !== $captainId && $player['pred'] > $viceCaptainPred) {
                $viceCaptainPred = $player['pred'];
                $viceCaptainId = $player['id'];
            }
        }

        // Build player list with positions for display
        $players = [];
        $positionCounter = 1;

        // Add starting 11 in formation order (GK, DEF, MID, FWD)
        foreach ([1, 2, 3, 4] as $elementType) {
            foreach ($starting11 as $p) {
                $player = $playerMap[$p['id']] ?? null;
                if ($player && (int) $player['position'] === $elementType) {
                    $players[] = [
                        'player_id' => $p['id'],
                        'web_name' => $player['web_name'],
                        'element_type' => $elementType,
                        'team' => (int) $player['club_id'],
                        'position' => $positionCounter++,
                        'predicted_points' => round($p['pred'], 1),
                        'expected_mins' => $this->calculateExpectedMins($player),
                        'multiplier' => $p['id'] === $captainId ? 2 : 1,
                        'is_captain' => $p['id'] === $captainId,
                        'is_vice_captain' => $p['id'] === $viceCaptainId,
                        'now_cost' => (int) $player['now_cost'],
                    ];
                }
            }
        }

        // Add bench players (not in starting 11) sorted by predicted points
        $benchPlayers = [];
        foreach ($squadIds as $playerId) {
            if (!in_array($playerId, $starting11Ids)) {
                $player = $playerMap[$playerId] ?? null;
                if ($player) {
                    $benchPlayers[] = [
                        'id' => $playerId,
                        'pred' => $predictions[$playerId][$gameweek] ?? 0,
                        'player' => $player,
                    ];
                }
            }
        }

        // Sort bench: GK first (for auto-sub rules), then by predicted points
        usort($benchPlayers, function ($a, $b) {
            $aIsGk = (int) $a['player']['position'] === 1;
            $bIsGk = (int) $b['player']['position'] === 1;
            if ($aIsGk !== $bIsGk) {
                return $aIsGk ? -1 : 1;
            }
            return $b['pred'] <=> $a['pred'];
        });

        foreach ($benchPlayers as $bp) {
            $player = $bp['player'];
            $players[] = [
                'player_id' => $bp['id'],
                'web_name' => $player['web_name'],
                'element_type' => (int) $player['position'],
                'team' => (int) $player['club_id'],
                'position' => $positionCounter++,
                'predicted_points' => round($bp['pred'], 1),
                'expected_mins' => $this->calculateExpectedMins($player),
                'multiplier' => 0,
                'is_captain' => false,
                'is_vice_captain' => false,
                'now_cost' => (int) $player['now_cost'],
            ];
        }

        // Calculate totals
        $startingTotal = 0;
        $benchTotal = 0;
        foreach ($players as $p) {
            if ($p['position'] <= 11) {
                $points = $p['predicted_points'] * $p['multiplier'];
                $startingTotal += $points;
            } else {
                $benchTotal += $p['predicted_points'];
            }
        }

        // Captain candidates shortlist
        $captainCandidates = self::findCaptainCandidates($starting11);

        return [
            'gameweek' => $gameweek,
            'players' => $players,
            'starting_total' => round($startingTotal, 1),
            'bench_total' => round($benchTotal, 1),
            'captain_id' => $captainId,
            'vice_captain_id' => $viceCaptainId,
            'captain_candidates' => $captainCandidates,
        ];
    }

    /**
     * Find captain candidates from a starting 11.
     * Returns players within the threshold of the top predicted scorer.
     *
     * @param array<int, array{id: int, pred: float}> $starting11
     * @param float $threshold Maximum point difference from top to be a candidate
     * @return array<int, array{player_id: int, predicted_points: float, margin: float}>
     */
    public static function findCaptainCandidates(array $starting11, float $threshold = 0.5): array
    {
        if (empty($starting11)) {
            return [];
        }

        // Sort by pred descending
        usort($starting11, fn($a, $b) => $b['pred'] <=> $a['pred']);

        $topPred = $starting11[0]['pred'];
        $candidates = [];

        foreach ($starting11 as $player) {
            $margin = round($topPred - $player['pred'], 2);
            if ($margin <= $threshold) {
                $candidates[] = [
                    'player_id' => $player['id'],
                    'predicted_points' => $player['pred'],
                    'margin' => $margin,
                ];
            } else {
                break; // Sorted, so all further are worse
            }
        }

        return $candidates;
    }

    private function findOptimalTransfers(
        array $currentSquad,
        array $predictions,
        array $gameweeks,
        array $playerMap,
        float $bank,
        int $freeTransfers,
        array $chipPlan,
        array $dgwTeams
    ): array {
        $recommendations = [];
        $squadCopy = $currentSquad;
        $bankCopy = $bank;

        // Score each current player based on upcoming fixtures
        $squadScores = [];
        foreach ($squadCopy as $playerId) {
            $player = $playerMap[$playerId] ?? null;
            if (!$player) {
                continue;
            }

            $totalPredicted = 0;
            foreach ($gameweeks as $gw) {
                $totalPredicted += $predictions[$playerId][$gw] ?? 0;
            }

            $squadScores[$playerId] = [
                'player' => $player,
                'total_predicted' => $totalPredicted,
                'avg_predicted' => $totalPredicted / count($gameweeks),
                'price' => (int) $player['now_cost'] / 10,
            ];
        }

        // Sort by predicted points (ascending = worst first)
        uasort($squadScores, fn($a, $b) => $a['total_predicted'] <=> $b['total_predicted']);

        // Find best replacements for worst performers
        $transfersToMake = min($freeTransfers + 1, 3); // Max 3 suggestions
        $suggestedOuts = array_slice(array_keys($squadScores), 0, $transfersToMake);

        foreach ($suggestedOuts as $outPlayerId) {
            $outPlayer = $squadScores[$outPlayerId]['player'];
            $outPrice = (int) $outPlayer['now_cost'] / 10;
            $position = (int) $outPlayer['position'];

            // Find best replacement
            $maxBudget = $outPrice + $bankCopy;
            $bestReplacement = null;
            $bestGain = 0;

            foreach ($playerMap as $inPlayerId => $inPlayer) {
                if (in_array($inPlayerId, $squadCopy)) {
                    continue;
                }
                if ((int) $inPlayer['position'] !== $position) {
                    continue;
                }

                $inPrice = (int) $inPlayer['now_cost'] / 10;
                if ($inPrice > $maxBudget) {
                    continue;
                }

                // Check team limit (max 3 per team)
                $teamCount = 0;
                foreach ($squadCopy as $sid) {
                    if (($playerMap[$sid]['club_id'] ?? 0) === (int) $inPlayer['club_id']) {
                        $teamCount++;
                    }
                }
                if ($teamCount >= 3) {
                    continue;
                }

                $inPredicted = 0;
                foreach ($gameweeks as $gw) {
                    $inPredicted += $predictions[$inPlayerId][$gw] ?? 0;
                }

                $outPredicted = $squadScores[$outPlayerId]['total_predicted'];
                $gain = $inPredicted - $outPredicted;

                if ($gain > $bestGain) {
                    $bestGain = $gain;
                    $bestReplacement = [
                        'player' => $inPlayer,
                        'predicted_gain' => round($gain, 1),
                        'price' => $inPrice,
                        'total_predicted' => round($inPredicted, 1),
                    ];
                }
            }

            if ($bestReplacement) {
                $isFree = count($recommendations) < $freeTransfers;
                $netGain = $bestReplacement['predicted_gain'] - ($isFree ? 0 : self::HIT_COST);

                $recommendations[] = [
                    'out' => [
                        'id' => $outPlayerId,
                        'web_name' => $outPlayer['web_name'],
                        'team' => (int) $outPlayer['club_id'],
                        'price' => $outPrice,
                        'total_predicted' => round($squadScores[$outPlayerId]['total_predicted'], 1),
                    ],
                    'in' => [
                        'id' => (int) $bestReplacement['player']['id'],
                        'web_name' => $bestReplacement['player']['web_name'],
                        'team' => (int) $bestReplacement['player']['club_id'],
                        'price' => $bestReplacement['price'],
                        'total_predicted' => $bestReplacement['total_predicted'],
                    ],
                    'predicted_gain' => $bestReplacement['predicted_gain'],
                    'is_free' => $isFree,
                    'hit_cost' => $isFree ? 0 : self::HIT_COST,
                    'net_gain' => round($netGain, 1),
                    // Free transfers recommended if net gain > 0
                    // Hits only recommended if net gain exceeds threshold (default 6 pts)
                    'recommended' => $isFree ? $netGain > 0 : $netGain >= self::HIT_THRESHOLD,
                ];

                // Update squad copy for subsequent recommendations
                $squadCopy = array_diff($squadCopy, [$outPlayerId]);
                $squadCopy[] = (int) $bestReplacement['player']['id'];
                $bankCopy = $bankCopy + $outPrice - $bestReplacement['price'];
            }
        }

        // Sort by net gain
        usort($recommendations, fn($a, $b) => $b['net_gain'] <=> $a['net_gain']);

        return $recommendations;
    }

    private function suggestChipTiming(
        array $currentSquad,
        array $predictions,
        array $gameweeks,
        array $playerMap,
        array $dgwTeams,
        array $existingPlan
    ): array {
        $suggestions = [];

        // Find best Bench Boost week (maximize bench points, prefer DGWs)
        if (!isset($existingPlan['bench_boost'])) {
            $bestBBGw = null;
            $bestBBValue = 0;

            foreach ($gameweeks as $gw) {
                $hasDgw = !empty($dgwTeams[$gw] ?? []);
                $multiplier = $hasDgw ? 1.5 : 1.0;

                // Estimate bench contribution
                $squadPreds = [];
                foreach ($currentSquad as $pid) {
                    $squadPreds[$pid] = $predictions[$pid][$gw] ?? 0;
                }
                arsort($squadPreds);
                $benchPreds = array_slice($squadPreds, 11, 4);
                $benchValue = array_sum($benchPreds) * $multiplier;

                if ($benchValue > $bestBBValue) {
                    $bestBBValue = $benchValue;
                    $bestBBGw = $gw;
                }
            }

            if ($bestBBGw) {
                $suggestions['bench_boost'] = [
                    'gameweek' => $bestBBGw,
                    'estimated_value' => round($bestBBValue, 1),
                    'has_dgw' => !empty($dgwTeams[$bestBBGw] ?? []),
                    'reason' => !empty($dgwTeams[$bestBBGw] ?? [])
                        ? 'Double gameweek maximizes bench points'
                        : 'Best expected bench output',
                ];
            }
        }

        // Find best Triple Captain week
        if (!isset($existingPlan['triple_captain'])) {
            $bestTCGw = null;
            $bestTCValue = 0;

            foreach ($gameweeks as $gw) {
                // Find captain candidate (highest predicted for the week)
                $maxPred = 0;
                foreach ($currentSquad as $pid) {
                    $pred = $predictions[$pid][$gw] ?? 0;
                    $maxPred = max($maxPred, $pred);
                }

                // TC value = extra captain points (normally 2x, TC is 3x, so +1x)
                $tcValue = $maxPred;
                $hasDgw = !empty($dgwTeams[$gw] ?? []);
                if ($hasDgw) {
                    $tcValue *= 1.3;
                }

                if ($tcValue > $bestTCValue) {
                    $bestTCValue = $tcValue;
                    $bestTCGw = $gw;
                }
            }

            if ($bestTCGw) {
                $suggestions['triple_captain'] = [
                    'gameweek' => $bestTCGw,
                    'estimated_value' => round($bestTCValue, 1),
                    'has_dgw' => !empty($dgwTeams[$bestTCGw] ?? []),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Calculate expected minutes for a player based on their stats.
     * Uses same logic as MinutesProbability for consistency.
     */
    /**
     * Estimate how many gameweeks until a player recovers from injury.
     *
     * @param int|null $chanceOfPlaying Current chance of playing percentage
     * @param string $news News/injury description from FPL
     * @return int|null Number of weeks to recovery, or null if unknown/long-term
     */
    private function estimateRecoveryWeeks(?int $chanceOfPlaying, string $news): ?int
    {
        // Try to parse expected return date from news
        // Common formats: "Expected back 21 Feb", "Expected back early March"
        if (preg_match('/expected back (\d{1,2}) (jan|feb|mar|apr|may)/i', $news, $matches)) {
            $day = (int) $matches[1];
            $monthMap = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5];
            $month = $monthMap[strtolower($matches[2])] ?? 2;
            $year = date('Y');

            // If month is before current month, assume next year
            if ($month < (int) date('n')) {
                $year++;
            }

            $returnDate = mktime(0, 0, 0, $month, $day, (int) $year);
            $today = time();
            $daysUntilReturn = max(0, ($returnDate - $today) / 86400);
            $weeksUntilReturn = ceil($daysUntilReturn / 7);

            return (int) min(6, max(1, $weeksUntilReturn));
        }

        // Check for keywords indicating long-term injury
        $longTermKeywords = ['season', 'months', 'surgery', 'acl', 'achilles', 'cruciate'];
        foreach ($longTermKeywords as $keyword) {
            if (stripos($news, $keyword) !== false) {
                return null; // Long-term - don't model recovery
            }
        }

        // Fall back to chance_of_playing based estimate
        if ($chanceOfPlaying !== null && $chanceOfPlaying > 0) {
            return match (true) {
                $chanceOfPlaying >= 75 => 1,
                $chanceOfPlaying >= 50 => 2,
                $chanceOfPlaying >= 25 => 3,
                default => 4,
            };
        }

        // chance_of_playing = 0 with no return date - assume 2-3 weeks typical injury
        if ($chanceOfPlaying === 0 && !empty($news)) {
            return 3;
        }

        return null;
    }

    /**
     * Calculate expected minutes for a player.
     *
     * @param array $player Player data
     * @param bool $whenFit If true, calculate expected mins when fully fit (ignores current injury)
     */
    private function calculateExpectedMins(array $player, bool $whenFit = false): int
    {
        $minutes = (int) ($player['minutes'] ?? 0);
        $appearances = (int) ($player['appearances'] ?? 0);
        $starts = (int) ($player['starts'] ?? 0);
        $teamGames = 24; // Approximate team games played

        if ($minutes === 0 || $appearances === 0) {
            return 10; // Low minutes for players with no data
        }

        // Minutes per appearance × probability of appearing
        $minsPerAppearance = $minutes / $appearances;

        if ($whenFit) {
            // When fit: estimate based on historical role (starter vs sub)
            // If they start most games they appear in, assume ~85 mins when fit
            $startRate = $appearances > 0 ? $starts / $appearances : 0;
            if ($startRate > 0.7) {
                return 85; // Regular starter
            } elseif ($startRate > 0.3) {
                return 65; // Rotation player
            } else {
                return 30; // Super sub
            }
        }

        $appearanceRate = min(0.98, $appearances / $teamGames);
        $expectedMins = $appearanceRate * $minsPerAppearance;

        return (int) round(min(95, $expectedMins));
    }
}
