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
        array $xMinsOverrides = [],
        array $fixedTransfers = [],
        float $ftValue = 1.5,
        string $depth = 'standard',
        bool $skipSolve = false,
    ): array {
        $currentGw = $this->gameweekService->getCurrentGameweek();
        $planFromGw = $this->gameweekService->getNextActionableGameweek();
        $upcomingGws = $this->gameweekService->getUpcomingGameweeks(self::PLANNING_HORIZON, $planFromGw);
        $fixtureCounts = $this->gameweekService->getFixtureCounts($upcomingGws);

        // Get manager's current squad — use current GW picks so squad matches bank
        // (last_deadline_bank reflects post-transfer state for the current GW)
        $managerData = $this->fplClient->entry($managerId)->getRaw();
        $gwStarted = $this->gameweekService->hasGameweekStarted($currentGw);
        if ($gwStarted) {
            // GW has started — picks endpoint exists for current GW
            $picks = $this->fplClient->entry($managerId)->picks($currentGw);
        } else {
            // GW hasn't started — picks only exist for previous GW
            $picks = $this->fplClient->entry($managerId)->picks(max(1, $currentGw - 1));
        }

        $currentSquad = array_column($picks['picks'] ?? [], 'element');
        $bank = ($managerData['last_deadline_bank'] ?? 0) / 10;
        $squadValue = ($managerData['last_deadline_value'] ?? 1000) / 10;

        // Derive FT count from manager history if caller didn't specify
        $apiFreeTransfers = $this->calculateFreeTransfers($managerId, $currentGw);
        if ($freeTransfers <= 0) {
            $freeTransfers = $apiFreeTransfers;
        }

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

        // Fetch team games played for xMins computation
        $teamGamesRows = $this->db->fetchAll(
            "SELECT club_id, COUNT(*) as games FROM (
                SELECT home_club_id as club_id FROM fixtures WHERE finished = 1
                UNION ALL
                SELECT away_club_id as club_id FROM fixtures WHERE finished = 1
            ) GROUP BY club_id"
        );
        $teamGames = [];
        foreach ($teamGamesRows as $row) {
            $teamGames[(int) $row['club_id']] = (int) $row['games'];
        }

        // Unified per-GW xMins pipeline: decay + injury modeling + user overrides
        $perGwXMins = $this->computePerGwXMins($playerMap, $upcomingGws, $teamGames, $xMinsOverrides);
        $squadXMins = array_intersect_key($perGwXMins, array_flip($currentSquad));
        $predictions = $this->applyPerGwXMinsToPredictions($predictions, $squadXMins, $playerMap, $teamGames);

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
            $formations[$gw] = $this->buildFormationData($currentSquad, $predictions, $gw, $playerMap, $perGwXMins);
        }

        // Run beam search path solver (skip when only squad data is needed)
        $paths = [];
        if (!$skipSolve) {
            $pathSolver = new PathSolver(ftValue: $ftValue, depth: $depth);
            $paths = $pathSolver->solve(
                $currentSquad,
                $predictions,
                $upcomingGws,
                $playerMap,
                $bank,
                $freeTransfers,
                $fixedTransfers,
            );
        }

        return [
            'current_gameweek' => $planFromGw,
            'planning_horizon' => $upcomingGws,
            'current_squad' => [
                'player_ids' => $currentSquad,
                'bank' => $bank,
                'squad_value' => $squadValue,
                'free_transfers' => $freeTransfers,
                'api_free_transfers' => $apiFreeTransfers,
                'predicted_points' => $currentSquadPredictions,
                'formations' => $formations,
                'per_gw_xmins' => array_intersect_key($perGwXMins, array_flip($currentSquad)),
            ],
            'dgw_teams' => $dgwTeams,
            'recommendations' => $recommendations,
            'chip_suggestions' => $chipSuggestions,
            'chip_plan' => $chipPlan,
            'paths' => $paths,
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
     * Delegates to PathSolver's shared static implementation.
     */
    private function selectOptimalStarting11(array $byPosition): array
    {
        return PathSolver::selectOptimalStarting11($byPosition);
    }

    /**
     * Build formation data with player details for display.
     */
    private function buildFormationData(
        array $squadIds,
        array $predictions,
        int $gameweek,
        array $playerMap,
        array $perGwXMins = []
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
                        'expected_mins' => $perGwXMins[$p['id']][$gameweek] ?? $this->calculateExpectedMins($player),
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
                'expected_mins' => $perGwXMins[$bp['id']][$gameweek] ?? $this->calculateExpectedMins($player),
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
     * Compute per-GW expected minutes for each player.
     *
     * Pipeline:
     * 1. Base fit xMins from calculateExpectedMins($player, whenFit: true)
     * 2. Decay: base × 0.97^gwOffset
     * 3. Injury modeling: 0 for GWs before return, base after
     * 4. User overrides (highest priority)
     *
     * @param array $playerMap Player ID => player data
     * @param array $upcomingGws List of upcoming gameweek numbers
     * @param array $teamGames Team ID => games played
     * @param array $userOverrides Player ID => int (uniform) or array (per-GW)
     * @return array Player ID => [gw => xMins]
     */
    protected function computePerGwXMins(
        array $playerMap,
        array $upcomingGws,
        array $teamGames,
        array $userOverrides = []
    ): array {
        $result = [];
        $decayRate = 0.97;

        foreach ($playerMap as $playerId => $player) {
            $baseFitXMins = $this->calculateExpectedMins($player, true);
            $chanceOfPlaying = $player['chance_of_playing'] ?? null;
            $news = $player['news'] ?? '';

            // Determine injury state
            $isInjured = $chanceOfPlaying !== null && (int) $chanceOfPlaying < 100;
            $recoveryWeeks = null;

            if ($isInjured) {
                $recoveryWeeks = $this->estimateRecoveryWeeks(
                    $chanceOfPlaying !== null ? (int) $chanceOfPlaying : null,
                    $news
                );
            }

            $perGw = [];
            foreach ($upcomingGws as $idx => $gw) {
                $decayed = (int) round($baseFitXMins * pow($decayRate, $idx));

                if (!$isInjured) {
                    // Fully fit
                    $perGw[$gw] = $decayed;
                } elseif ($recoveryWeeks === null) {
                    // Long-term injury (no return date)
                    $perGw[$gw] = 0;
                } elseif ((int) $chanceOfPlaying === 0) {
                    // Confirmed out — 0 until recovery, then base
                    if ($idx < $recoveryWeeks) {
                        $perGw[$gw] = 0;
                    } else {
                        $perGw[$gw] = $decayed;
                    }
                } else {
                    // Partially available (chance_of_playing 25-75) — linear ramp
                    if ($idx < $recoveryWeeks) {
                        $rampFraction = ($idx + 1) / $recoveryWeeks;
                        $perGw[$gw] = (int) round($baseFitXMins * $rampFraction * pow($decayRate, $idx));
                    } else {
                        $perGw[$gw] = $decayed;
                    }
                }
            }

            // Apply user overrides (highest priority)
            $override = $userOverrides[$playerId] ?? null;
            if ($override !== null) {
                if (is_array($override)) {
                    // Per-GW overrides: only replace specified GWs
                    foreach ($override as $owGw => $owVal) {
                        $owGw = (int) $owGw;
                        if (isset($perGw[$owGw])) {
                            $perGw[$owGw] = (int) $owVal;
                        }
                    }
                } else {
                    // Uniform override: all GWs get the same value
                    foreach ($perGw as $gw => &$val) {
                        $val = (int) $override;
                    }
                    unset($val);
                }
            }

            $result[$playerId] = $perGw;
        }

        return $result;
    }

    /**
     * Apply per-GW xMins to cached predictions.
     *
     * For non-zero cached predictions: scale by gw_xMins / naturalXMins
     * For zero cached predictions (injured at prediction time): estimate "fit" prediction
     * as total_points / max(1, appearances), then scale by gw_xMins / baseFitXMins
     *
     * @param array $predictions Player ID => [gw => predicted_points]
     * @param array $perGwXMins Player ID => [gw => xMins]
     * @param array $playerMap Player ID => player data
     * @param array $teamGames Team ID => games played
     * @return array Adjusted predictions (same structure)
     */
    protected function applyPerGwXMinsToPredictions(
        array $predictions,
        array $perGwXMins,
        array $playerMap,
        array $teamGames
    ): array {
        $minutesProb = new \SuperFPL\Api\Prediction\MinutesProbability();

        foreach ($perGwXMins as $playerId => $gwXMins) {
            if (!isset($predictions[$playerId])) {
                continue;
            }

            $player = $playerMap[$playerId] ?? null;
            if (!$player) {
                continue;
            }

            $clubId = (int) ($player['club_id'] ?? 0);
            $tGames = $teamGames[$clubId] ?? 24;

            // Get natural xMins (what MinutesProbability would compute with current data)
            $minsResult = $minutesProb->calculate($player, $tGames);
            $naturalXMins = $minsResult['expected_mins'];

            // Get baseFitXMins for zero-prediction recovery
            $baseFitXMins = $this->calculateExpectedMins($player, true);

            // Check if all cached predictions are zero (injured at prediction time)
            $allZero = true;
            foreach ($predictions[$playerId] as $pts) {
                if ($pts > 0) {
                    $allZero = false;
                    break;
                }
            }

            foreach ($predictions[$playerId] as $gw => &$points) {
                $gwMins = $gwXMins[$gw] ?? 0;

                if ($gwMins <= 0) {
                    $points = 0.0;
                    continue;
                }

                if (!$allZero && $naturalXMins > 0) {
                    // Non-zero cached: scale proportionally
                    $scale = $gwMins / $naturalXMins;
                    $scale = max(0, min(1.5, $scale));
                    $points = round($points * $scale, 2);
                } elseif ($allZero) {
                    // Zero cached (injured at prediction time): use fit estimate
                    $appearances = max(1, (int) ($player['appearances'] ?? 1));
                    $totalPoints = (int) ($player['total_points'] ?? 0);
                    $fitEstimate = $totalPoints / $appearances;

                    if ($baseFitXMins > 0) {
                        $scale = $gwMins / $baseFitXMins;
                        $scale = max(0, min(1.5, $scale));
                        $points = round($fitEstimate * $scale, 2);
                    }
                }
            }
            unset($points);
        }

        return $predictions;
    }

    /**
     * Estimate how many gameweeks until a player recovers from injury.
     *
     * @param int|null $chanceOfPlaying Current chance of playing percentage
     * @param string $news News/injury description from FPL
     * @return int|null Number of weeks to recovery, or null if unknown/long-term
     */
    protected function estimateRecoveryWeeks(?int $chanceOfPlaying, string $news): ?int
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
    protected function calculateExpectedMins(array $player, bool $whenFit = false): int
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

    /**
     * Calculate free transfers available for the next actionable gameweek
     * by replaying the manager's transfer history.
     *
     * Rules:
     * - GW1 has unlimited transfers (initial squad selection); FT tracking starts at GW2 with 1 FT
     * - Each GW: if 0 transfers made, bank +1 FT (max 5)
     * - If transfers made: consume FTs, then reset to 1 for next GW
     * - If hit taken (event_transfers_cost > 0): used more than available, reset to 1
     * - Wildcard/Free Hit: unlimited transfers that GW, FT does NOT bank — stays same as before chip
     * - GW16 (2024-25 season): all managers received a boost to 5 FTs
     */
    private function calculateFreeTransfers(int $managerId, int $currentGw): int
    {
        try {
            $history = $this->fplClient->entry($managerId)->history();
        } catch (\Throwable $e) {
            return 1; // Safe default
        }

        $gwHistory = $history['current'] ?? [];
        $chips = $history['chips'] ?? [];

        // Index chips by gameweek
        $chipsByGw = [];
        foreach ($chips as $chip) {
            $chipsByGw[(int) $chip['event']] = $chip['name'];
        }

        $ft = 1; // Start with 1 FT at GW2

        foreach ($gwHistory as $gw) {
            $event = (int) $gw['event'];

            // Skip GW1 — unlimited transfers for initial squad
            if ($event <= 1) {
                continue;
            }

            // GW16 boost: all managers set to 5 FTs
            if ($event === 16) {
                $ft = 5;
            }

            $chip = $chipsByGw[$event] ?? null;
            $transfers = (int) $gw['event_transfers'];
            $hitCost = (int) $gw['event_transfers_cost'];

            // Wildcard or Free Hit: unlimited transfers, FT stays unchanged for next GW
            if ($chip === 'wildcard' || $chip === 'freehit') {
                // FT carries over unchanged — no banking, no consuming
                continue;
            }

            if ($transfers === 0) {
                // No transfers: bank +1 FT (max 5)
                $ft = min(5, $ft + 1);
            } elseif ($hitCost > 0) {
                // Took a hit: used more FTs than available, reset to 1
                $ft = 1;
            } else {
                // Used some/all free transfers without a hit
                $ft = max(1, $ft - $transfers + 1);
            }
        }

        return max(1, min(5, $ft));
    }
}
