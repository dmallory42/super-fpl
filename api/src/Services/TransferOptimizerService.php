<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class TransferOptimizerService
{
    private const PLANNING_HORIZON = 6; // Gameweeks to look ahead
    private const HIT_COST = 4; // Points cost per extra transfer

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
     */
    public function getOptimalPlan(
        int $managerId,
        array $chipPlan = [],
        int $freeTransfers = 1
    ): array {
        $currentGw = $this->gameweekService->getCurrentGameweek();
        $upcomingGws = $this->gameweekService->getUpcomingGameweeks(self::PLANNING_HORIZON);
        $fixtureCounts = $this->gameweekService->getFixtureCounts($upcomingGws);

        // Get manager's current squad
        $managerData = $this->fplClient->getEntry($managerId);
        $picks = $this->fplClient->getEntryPicks($managerId, max(1, $currentGw - 1));

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

        return [
            'current_gameweek' => $currentGw,
            'planning_horizon' => $upcomingGws,
            'current_squad' => [
                'player_ids' => $currentSquad,
                'bank' => $bank,
                'squad_value' => $squadValue,
                'free_transfers' => $freeTransfers,
                'predicted_points' => $currentSquadPredictions,
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
            $gwTotal = 0;
            foreach ($squadIds as $playerId) {
                $gwTotal += $predictions[$playerId][$gw] ?? 0;
            }
            // Approximate: assume best 11 is 85% of total squad points
            $result[$gw] = round($gwTotal * 0.85, 1);
        }

        $result['total'] = array_sum($result);
        return $result;
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
            $position = (int) $outPlayer['element_type'];

            // Find best replacement
            $maxBudget = $outPrice + $bankCopy;
            $bestReplacement = null;
            $bestGain = 0;

            foreach ($playerMap as $inPlayerId => $inPlayer) {
                if (in_array($inPlayerId, $squadCopy)) {
                    continue;
                }
                if ((int) $inPlayer['element_type'] !== $position) {
                    continue;
                }

                $inPrice = (int) $inPlayer['now_cost'] / 10;
                if ($inPrice > $maxBudget) {
                    continue;
                }

                // Check team limit (max 3 per team)
                $teamCount = 0;
                foreach ($squadCopy as $sid) {
                    if (($playerMap[$sid]['team'] ?? 0) === (int) $inPlayer['team']) {
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
                        'team' => (int) $outPlayer['team'],
                        'price' => $outPrice,
                        'total_predicted' => round($squadScores[$outPlayerId]['total_predicted'], 1),
                    ],
                    'in' => [
                        'id' => (int) $bestReplacement['player']['id'],
                        'web_name' => $bestReplacement['player']['web_name'],
                        'team' => (int) $bestReplacement['player']['team'],
                        'price' => $bestReplacement['price'],
                        'total_predicted' => $bestReplacement['total_predicted'],
                    ],
                    'predicted_gain' => $bestReplacement['predicted_gain'],
                    'is_free' => $isFree,
                    'hit_cost' => $isFree ? 0 : self::HIT_COST,
                    'net_gain' => round($netGain, 1),
                    'recommended' => $netGain > 0,
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
}
