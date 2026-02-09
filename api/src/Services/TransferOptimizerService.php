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
                        'expected_mins' => (int) round((float) ($player['expected_mins_if_fit'] ?? 0)),
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
                'expected_mins' => (int) round((float) ($player['expected_mins_if_fit'] ?? 0)),
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
