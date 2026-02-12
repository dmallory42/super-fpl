<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

class PathSolver
{
    private const HIT_COST = 4;
    private const MAX_FT = 5;
    private const CHIP_NONE = null;
    private const CHIP_WILDCARD = 'wildcard';
    private const CHIP_FREE_HIT = 'free_hit';
    private const CHIP_BENCH_BOOST = 'bench_boost';
    private const CHIP_TRIPLE_CAPTAIN = 'triple_captain';

    // [beamWidth, candidatesPerPos, maxTransfersPerGw]
    private const DEPTH_PRESETS = [
        'quick'    => [15,  8, 2],
        'standard' => [30, 10, 3],
        'deep'     => [60, 15, 4],
    ];

    private int $beamWidth;
    private int $candidatesPerPos;
    private int $maxTransfersPerGw;

    public function __construct(
        private float $ftValue = 1.5,
        private string $depth = 'standard',
    ) {
        [$this->beamWidth, $this->candidatesPerPos, $this->maxTransfersPerGw] =
            self::DEPTH_PRESETS[$this->depth] ?? self::DEPTH_PRESETS['standard'];
    }

    /**
     * Run beam search to find the top 3 optimal transfer paths.
     *
     * @param int[]   $squadIds       15 player IDs
     * @param array   $predictions    playerId => [gw => pts]
     * @param int[]   $gameweeks      ordered list of GWs in planning horizon
     * @param array   $playerMap      playerId => player row
     * @param float   $bank           bank balance in millions
     * @param int     $freeTransfers  FTs available for first GW
     * @param array   $fixedTransfers [{gameweek, out, in}]
     * @param array   $chipPlan       [chip => gameweek]
     * @return array  Top 3 diverse TransferPath arrays
     */
    public function solve(
        array $squadIds,
        array $predictions,
        array $gameweeks,
        array $playerMap,
        float $bank,
        int $freeTransfers,
        array $fixedTransfers = [],
        array $chipPlan = [],
    ): array {
        // Build candidate pools per position
        $candidatePools = $this->buildCandidatePools($squadIds, $predictions, $gameweeks, $playerMap);

        // Index fixed transfers by GW
        $fixedByGw = [];
        foreach ($fixedTransfers as $ft) {
            $fixedByGw[$ft['gameweek']][] = $ft;
        }

        // Calculate hold score — apply only fixed transfers (mandatory constraints),
        // no additional solver moves. This makes score_vs_hold show marginal value only.
        $holdScore = 0.0;
        $holdSquad = $squadIds;
        $holdFt = $freeTransfers;
        $holdBank = $bank;
        foreach ($gameweeks as $gw) {
            $fixed = $fixedByGw[$gw] ?? [];
            $chip = $this->chipForGameweek($chipPlan, $gw);

            foreach ($fixed as $ft) {
                $outId = $ft['out'];
                $inId = $ft['in'];
                if (in_array($outId, $holdSquad)) {
                    $holdSquad = array_values(array_diff($holdSquad, [$outId]));
                    $holdSquad[] = $inId;
                }
            }

            $numFixed = count($fixed);
            if ($chip === self::CHIP_WILDCARD || $chip === self::CHIP_FREE_HIT) {
                $bestChipSquad = $this->optimizeChipSquads(
                    $holdSquad,
                    $holdBank,
                    $gw,
                    $gameweeks,
                    $predictions,
                    $playerMap,
                    $chip,
                    1
                )[0] ?? null;

                if ($bestChipSquad !== null) {
                    $holdScore += $this->evaluateSquad(
                        $bestChipSquad['squad_ids'],
                        $predictions,
                        $gw,
                        $playerMap,
                        $chip
                    );
                    if ($chip === self::CHIP_WILDCARD) {
                        $holdSquad = $bestChipSquad['squad_ids'];
                        $holdBank = $bestChipSquad['bank'];
                    }
                } else {
                    $holdScore += $this->evaluateSquad($holdSquad, $predictions, $gw, $playerMap, $chip);
                }
            } else {
                $hitCost = max(0, $numFixed - $holdFt) * self::HIT_COST;
                $holdScore += $this->evaluateSquad($holdSquad, $predictions, $gw, $playerMap, $chip) - $hitCost;
            }

            // Update FT for next GW
            if ($chip === self::CHIP_WILDCARD || $chip === self::CHIP_FREE_HIT) {
                // Keep FT unchanged through WC/FH.
                $holdFt = $holdFt;
            } elseif ($numFixed === 0) {
                $holdFt = min(self::MAX_FT, $holdFt + 1);
            } elseif ($numFixed <= $holdFt) {
                $holdFt = min(self::MAX_FT, $holdFt - $numFixed + 1);
            } else {
                $holdFt = 1;
            }
        }

        // Initialize beam with starting state
        $initialState = [
            'squad_ids' => $squadIds,
            'bank' => $bank,
            'ft' => $freeTransfers,
            'score' => 0.0,        // internal score (includes FT value)
            'display_score' => 0.0, // actual predicted points
            'transfers_by_gw' => [],
            'total_hits' => 0,
        ];

        $beam = [$initialState];

        // Process each gameweek
        foreach ($gameweeks as $gw) {
            $fixed = $fixedByGw[$gw] ?? [];
            $chip = $this->chipForGameweek($chipPlan, $gw);
            $children = [];

            foreach ($beam as $state) {
                $newChildren = $this->generateChildren(
                    $state,
                    $gw,
                    $predictions,
                    $playerMap,
                    $candidatePools,
                    $fixed,
                    $gameweeks,
                    $chip
                );
                foreach ($newChildren as $child) {
                    $children[] = $child;
                }
            }

            // Deduplicate by state hash
            $children = $this->deduplicate($children);

            // Sort by internal score descending, keep top B
            usort($children, fn($a, $b) => $b['score'] <=> $a['score']);
            $beam = array_slice($children, 0, $this->beamWidth);
        }

        // Select top 3 diverse paths
        $paths = $this->selectDiversePaths($beam, 3);

        // Format output
        $result = [];
        foreach ($paths as $idx => $state) {
            $result[] = [
                'id' => $idx + 1,
                'total_score' => round($state['display_score'], 1),
                'score_vs_hold' => round($state['display_score'] - $holdScore, 1),
                'total_hits' => $state['total_hits'],
                'transfers_by_gw' => $state['transfers_by_gw'],
            ];
        }

        return $result;
    }

    /**
     * Generate child states for a single beam state at a given GW.
     */
    private function generateChildren(
        array $state,
        int $gw,
        array $predictions,
        array $playerMap,
        array $candidatePools,
        array $fixedTransfers,
        array $gameweeks,
        ?string $chip = self::CHIP_NONE,
    ): array {
        $children = [];
        // WC/FH: dedicated chip transition for this GW (ignore normal transfer generation).
        if ($chip === self::CHIP_WILDCARD || $chip === self::CHIP_FREE_HIT) {
            return $this->generateChipSquadChildren($state, $gw, $predictions, $playerMap, $gameweeks, $chip);
        }

        // Apply fixed transfers first (if any for this GW)
        if (!empty($fixedTransfers)) {
            $fixedState = $this->applyFixedTransfers($state, $gw, $fixedTransfers, $predictions, $playerMap, $gameweeks, $chip);
            if ($fixedState !== null) {
                // Generate additional optional transfers on top of fixed ones
                $children[] = $fixedState;
                // Also try additional single transfers beyond the fixed ones
                $additionalChildren = $this->generateSingleTransfers(
                    $fixedState,
                    $gw,
                    $predictions,
                    $playerMap,
                    $candidatePools,
                    $gameweeks,
                    true, // skip re-evaluating GW score, it's already done
                    $chip
                );
                foreach ($additionalChildren as $c) {
                    $children[] = $c;
                }
            }
            return $children;
        }

        // Option 1: BANK — make 0 transfers, gain +1 FT
        $bankState = $this->makeBank($state, $gw, $predictions, $playerMap, $gameweeks, $chip);
        $children[] = $bankState;

        // Option 2: SINGLE TRANSFERS
        $singleChildren = $this->generateSingleTransfers($state, $gw, $predictions, $playerMap, $candidatePools, $gameweeks, false, $chip);
        foreach ($singleChildren as $c) {
            $children[] = $c;
        }

        // Option 3: MULTI-TRANSFER COMBOS (2+ transfers in one GW)
        if ($this->maxTransfersPerGw >= 2) {
            $multiChildren = $this->generateMultiTransfers($state, $gw, $predictions, $playerMap, $candidatePools, $gameweeks, $chip);
            foreach ($multiChildren as $c) {
                $children[] = $c;
            }
        }

        return $children;
    }

    /**
     * Banking: make 0 transfers, gain +1 FT.
     */
    private function makeBank(
        array $state,
        int $gw,
        array $predictions,
        array $playerMap,
        array $gameweeks,
        ?string $chip = self::CHIP_NONE
    ): array
    {
        $gwScore = $this->evaluateSquad($state['squad_ids'], $predictions, $gw, $playerMap, $chip);
        $newFt = min($state['ft'] + 1, self::MAX_FT);

        $newState = $state;
        $newState['score'] += $gwScore + $this->ftValue; // FT bonus for banking
        $newState['display_score'] += $gwScore;
        $newState['ft'] = $newFt;
        $newState['transfers_by_gw'][$gw] = [
            'action' => 'bank',
            'ft_available' => $state['ft'],
            'ft_after' => $newFt,
            'moves' => [],
            'hit_cost' => 0,
            'gw_score' => round($gwScore, 1),
            'squad_ids' => $state['squad_ids'],
            'bank' => $state['bank'],
            'chip_played' => $chip,
        ];

        return $newState;
    }

    /**
     * Generate single transfer children.
     */
    private function generateSingleTransfers(
        array $state,
        int $gw,
        array $predictions,
        array $playerMap,
        array $candidatePools,
        array $gameweeks,
        bool $isAdditional = false,
        ?string $chip = self::CHIP_NONE,
    ): array {
        $children = [];
        $squad = $state['squad_ids'];
        $ft = $state['ft'];

        // Rank out-players by weakness (lowest predicted for remaining GWs)
        $outCandidates = $this->rankOutPlayers($squad, $predictions, $gw, $gameweeks, $playerMap);
        $outCandidates = array_slice($outCandidates, 0, 15);

        foreach ($outCandidates as $outId) {
            $outPlayer = $playerMap[$outId] ?? null;
            if (!$outPlayer) continue;

            $position = (int)$outPlayer['position'];
            $outPrice = (int)$outPlayer['now_cost'] / 10;
            $pool = $candidatePools[$position] ?? [];

            foreach ($pool as $candidate) {
                $inId = $candidate['id'];
                if (in_array($inId, $squad)) continue;

                $inPrice = (int)$candidate['cost'] / 10;
                if ($inPrice > $outPrice + $state['bank']) continue;

                // Team limit check
                if (!$this->checkTeamLimit($squad, $outId, $inId, $playerMap)) continue;

                // Calculate transfer cost
                $transfersUsed = $isAdditional
                    ? count($state['transfers_by_gw'][$gw]['moves'] ?? []) + 1
                    : 1;

                // For additional transfers on fixed states
                $baseFtUsed = $isAdditional
                    ? count($state['transfers_by_gw'][$gw]['moves'] ?? [])
                    : 0;
                $totalTransfers = $baseFtUsed + 1;

                $hitCost = 0;
                $newFt = 0;
                if ($totalTransfers <= $ft) {
                    $newFt = min($ft - $totalTransfers + 1, self::MAX_FT);
                    $hitCost = 0;
                } else {
                    $newFt = 1;
                    $hitCost = ($totalTransfers - $ft) * self::HIT_COST;
                }

                // Build new squad
                $newSquad = array_values(array_diff($squad, [$outId]));
                $newSquad[] = $inId;
                $newBank = $state['bank'] + $outPrice - $inPrice;

                $gwScore = $this->evaluateSquad($newSquad, $predictions, $gw, $playerMap, $chip);
                $ftOpportunityCost = min($totalTransfers, $ft) * $this->ftValue;
                // Hits destroy FT flexibility — penalize proportional to ftValue
                $hitAversion = $hitCost > 0
                    ? $this->ftValue * ($hitCost / self::HIT_COST)
                    : 0;

                $newState = $state;
                $newState['squad_ids'] = $newSquad;
                $newState['bank'] = round($newBank, 1);
                $newState['ft'] = $newFt;

                $newMove = [
                    'out_id' => $outId,
                    'out_name' => $outPlayer['web_name'],
                    'out_team' => (int)$outPlayer['club_id'],
                    'out_price' => round($outPrice, 1),
                    'in_id' => $inId,
                    'in_name' => $playerMap[$inId]['web_name'] ?? '',
                    'in_team' => (int)($playerMap[$inId]['club_id'] ?? 0),
                    'in_price' => round($inPrice, 1),
                    'gain' => round(($predictions[$inId][$gw] ?? 0) - ($predictions[$outId][$gw] ?? 0), 1),
                    'is_free' => $hitCost === 0,
                ];

                if ($isAdditional) {
                    // The state already has this GW scored from applyFixedTransfers.
                    // Replace the GW contribution instead of adding to avoid double-counting.
                    $prevGwData = $state['transfers_by_gw'][$gw] ?? [];
                    $prevGwScore = $prevGwData['gw_score'] ?? 0;
                    $prevHitCost = $prevGwData['hit_cost'] ?? 0;
                    $prevRawScore = $prevGwScore + $prevHitCost;
                    $prevNumMoves = count($prevGwData['moves'] ?? []);
                    $prevFtAvail = $prevGwData['ft_available'] ?? $ft;
                    $prevFtOpp = min($prevNumMoves, $prevFtAvail) * $this->ftValue;
                    $prevHitAversion = $prevHitCost > 0 ? $this->ftValue * ($prevHitCost / self::HIT_COST) : 0;

                    $prevInternalContrib = $prevRawScore - $prevHitCost - $prevFtOpp - $prevHitAversion;
                    $newInternalContrib = $gwScore - $hitCost - $ftOpportunityCost - $hitAversion;

                    $newState['score'] += $newInternalContrib - $prevInternalContrib;
                    $newState['display_score'] += ($gwScore - $hitCost) - $prevGwScore;

                    $prevHits = $prevHitCost > 0 ? (int)($prevHitCost / self::HIT_COST) : 0;
                    $newHits = $hitCost > 0 ? (int)($hitCost / self::HIT_COST) : 0;
                    $newState['total_hits'] += $newHits - $prevHits;

                    // Merge fixed moves with the additional move
                    $allMoves = $prevGwData['moves'] ?? [];
                    $allMoves[] = $newMove;
                } else {
                    $newState['score'] += $gwScore - $hitCost - $ftOpportunityCost - $hitAversion;
                    $newState['display_score'] += $gwScore - $hitCost;
                    $newState['total_hits'] += $hitCost > 0 ? (int)($hitCost / self::HIT_COST) : 0;
                    $allMoves = [$newMove];
                }

                $newState['transfers_by_gw'][$gw] = [
                    'action' => 'transfer',
                    'ft_available' => $isAdditional ? ($state['transfers_by_gw'][$gw]['ft_available'] ?? $ft) : $ft,
                    'ft_after' => $newFt,
                    'moves' => $allMoves,
                    'hit_cost' => $hitCost,
                    'gw_score' => round($gwScore - $hitCost, 1),
                    'squad_ids' => $newSquad,
                    'bank' => round($newBank, 1),
                    'chip_played' => $chip,
                ];

                $children[] = $newState;
            }
        }

        return $children;
    }

    /**
     * Generate multi-transfer (2+) children.
     */
    private function generateMultiTransfers(
        array $state,
        int $gw,
        array $predictions,
        array $playerMap,
        array $candidatePools,
        array $gameweeks,
        ?string $chip = self::CHIP_NONE,
    ): array {
        $children = [];
        $squad = $state['squad_ids'];
        $ft = $state['ft'];

        // Only try 2-transfer combos (3+ is too expensive computationally for beam search)
        $outCandidates = $this->rankOutPlayers($squad, $predictions, $gw, $gameweeks, $playerMap);
        $topOuts = array_slice($outCandidates, 0, 5);

        // Generate pairs
        for ($i = 0; $i < count($topOuts); $i++) {
            for ($j = $i + 1; $j < count($topOuts); $j++) {
                $outId1 = $topOuts[$i];
                $outId2 = $topOuts[$j];

                $out1 = $playerMap[$outId1] ?? null;
                $out2 = $playerMap[$outId2] ?? null;
                if (!$out1 || !$out2) continue;

                $pos1 = (int)$out1['position'];
                $pos2 = (int)$out2['position'];
                $outPrice1 = (int)$out1['now_cost'] / 10;
                $outPrice2 = (int)$out2['now_cost'] / 10;

                $pool1 = array_slice($candidatePools[$pos1] ?? [], 0, 5);
                $pool2 = array_slice($candidatePools[$pos2] ?? [], 0, 5);

                foreach ($pool1 as $cand1) {
                    foreach ($pool2 as $cand2) {
                        $inId1 = $cand1['id'];
                        $inId2 = $cand2['id'];

                        // Skip if same player or already in squad
                        if ($inId1 === $inId2) continue;
                        if (in_array($inId1, $squad) && $inId1 !== $outId2) continue;
                        if (in_array($inId2, $squad) && $inId2 !== $outId1) continue;

                        $inPrice1 = (int)$cand1['cost'] / 10;
                        $inPrice2 = (int)$cand2['cost'] / 10;

                        // Budget check
                        $newBank = $state['bank'] + $outPrice1 + $outPrice2 - $inPrice1 - $inPrice2;
                        if ($newBank < 0) continue;

                        // Build new squad
                        $newSquad = array_values(array_diff($squad, [$outId1, $outId2]));
                        $newSquad[] = $inId1;
                        $newSquad[] = $inId2;

                        // Team limit check for both
                        if (!$this->checkTeamLimitMulti($newSquad, $playerMap)) continue;

                        // Calculate costs
                        $numTransfers = 2;
                        $hitCost = 0;
                        $newFt = 0;
                        if ($numTransfers <= $ft) {
                            $newFt = min($ft - $numTransfers + 1, self::MAX_FT);
                        } else {
                            $newFt = 1;
                            $hitCost = ($numTransfers - $ft) * self::HIT_COST;
                        }

                        $gwScore = $this->evaluateSquad($newSquad, $predictions, $gw, $playerMap, $chip);
                        $ftOpportunityCost = min($numTransfers, $ft) * $this->ftValue;
                        $hitAversion = $hitCost > 0
                            ? $this->ftValue * ($hitCost / self::HIT_COST)
                            : 0;

                        $newState = $state;
                        $newState['squad_ids'] = $newSquad;
                        $newState['bank'] = round($newBank, 1);
                        $newState['score'] += $gwScore - $hitCost - $ftOpportunityCost - $hitAversion;
                        $newState['display_score'] += $gwScore - $hitCost;
                        $newState['ft'] = $newFt;
                        $newState['total_hits'] += $hitCost > 0 ? (int)($hitCost / self::HIT_COST) : 0;
                        $newState['transfers_by_gw'][$gw] = [
                            'action' => 'transfer',
                            'ft_available' => $ft,
                            'ft_after' => $newFt,
                            'moves' => [
                                [
                                    'out_id' => $outId1,
                                    'out_name' => $out1['web_name'],
                                    'out_team' => (int)$out1['club_id'],
                                    'out_price' => round($outPrice1, 1),
                                    'in_id' => $inId1,
                                    'in_name' => $playerMap[$inId1]['web_name'] ?? '',
                                    'in_team' => (int)($playerMap[$inId1]['club_id'] ?? 0),
                                    'in_price' => round($inPrice1, 1),
                                    'gain' => round(($predictions[$inId1][$gw] ?? 0) - ($predictions[$outId1][$gw] ?? 0), 1),
                                    'is_free' => $hitCost === 0 || 1 <= $ft,
                                ],
                                [
                                    'out_id' => $outId2,
                                    'out_name' => $out2['web_name'],
                                    'out_team' => (int)$out2['club_id'],
                                    'out_price' => round($outPrice2, 1),
                                    'in_id' => $inId2,
                                    'in_name' => $playerMap[$inId2]['web_name'] ?? '',
                                    'in_team' => (int)($playerMap[$inId2]['club_id'] ?? 0),
                                    'in_price' => round($inPrice2, 1),
                                    'gain' => round(($predictions[$inId2][$gw] ?? 0) - ($predictions[$outId2][$gw] ?? 0), 1),
                                    'is_free' => $hitCost === 0 || 2 <= $ft,
                                ],
                            ],
                            'hit_cost' => $hitCost,
                            'gw_score' => round($gwScore - $hitCost, 1),
                            'squad_ids' => $newSquad,
                            'bank' => round($newBank, 1),
                            'chip_played' => $chip,
                        ];

                        $children[] = $newState;
                    }
                }
            }
        }

        return $children;
    }

    /**
     * Apply fixed transfers to a state.
     */
    private function applyFixedTransfers(
        array $state,
        int $gw,
        array $fixedTransfers,
        array $predictions,
        array $playerMap,
        array $gameweeks,
        ?string $chip = self::CHIP_NONE,
    ): ?array {
        $squad = $state['squad_ids'];
        $bank = $state['bank'];
        $ft = $state['ft'];
        $moves = [];
        $numTransfers = count($fixedTransfers);

        foreach ($fixedTransfers as $fixed) {
            $outId = $fixed['out'];
            $inId = $fixed['in'];

            if (!in_array($outId, $squad)) return null; // Invalid — player not in squad

            $outPlayer = $playerMap[$outId] ?? null;
            $inPlayer = $playerMap[$inId] ?? null;
            if (!$outPlayer || !$inPlayer) return null;

            $outPrice = (int)$outPlayer['now_cost'] / 10;
            $inPrice = (int)$inPlayer['now_cost'] / 10;

            if ($inPrice > $outPrice + $bank) return null; // Can't afford

            $squad = array_values(array_diff($squad, [$outId]));
            $squad[] = $inId;
            $bank = $bank + $outPrice - $inPrice;

            $moves[] = [
                'out_id' => $outId,
                'out_name' => $outPlayer['web_name'],
                'out_team' => (int)$outPlayer['club_id'],
                'out_price' => round($outPrice, 1),
                'in_id' => $inId,
                'in_name' => $inPlayer['web_name'],
                'in_team' => (int)$inPlayer['club_id'],
                'in_price' => round($inPrice, 1),
                'gain' => round(($predictions[$inId][$gw] ?? 0) - ($predictions[$outId][$gw] ?? 0), 1),
                'is_free' => true, // Will adjust below
            ];
        }

        // Team limit check
        if (!$this->checkTeamLimitMulti($squad, $playerMap)) return null;

        // Calculate FT/hit costs
        $hitCost = 0;
        $newFt = 0;
        if ($numTransfers <= $ft) {
            $newFt = min($ft - $numTransfers + 1, self::MAX_FT);
        } else {
            $newFt = 1;
            $hitCost = ($numTransfers - $ft) * self::HIT_COST;
        }

        // Mark which moves are free
        for ($i = 0; $i < count($moves); $i++) {
            $moves[$i]['is_free'] = ($i + 1) <= $ft;
        }

        $gwScore = $this->evaluateSquad($squad, $predictions, $gw, $playerMap, $chip);
        $ftOpportunityCost = min($numTransfers, $ft) * $this->ftValue;
        $hitAversion = $hitCost > 0
            ? $this->ftValue * ($hitCost / self::HIT_COST)
            : 0;

        $newState = $state;
        $newState['squad_ids'] = $squad;
        $newState['bank'] = round($bank, 1);
        $newState['score'] += $gwScore - $hitCost - $ftOpportunityCost - $hitAversion;
        $newState['display_score'] += $gwScore - $hitCost;
        $newState['ft'] = $newFt;
        $newState['total_hits'] += $hitCost > 0 ? (int)($hitCost / self::HIT_COST) : 0;
        $newState['transfers_by_gw'][$gw] = [
            'action' => count($moves) > 0 ? 'transfer' : 'bank',
            'ft_available' => $ft,
            'ft_after' => $newFt,
            'moves' => $moves,
            'hit_cost' => $hitCost,
            'gw_score' => round($gwScore - $hitCost, 1),
            'squad_ids' => $squad,
            'bank' => round($bank, 1),
            'chip_played' => $chip,
        ];

        return $newState;
    }

    /**
     * Evaluate a squad's predicted points for a single GW.
     * Selects optimal starting 11 + captain bonus.
     */
    private function evaluateSquad(
        array $squadIds,
        array $predictions,
        int $gw,
        array $playerMap,
        ?string $chip = self::CHIP_NONE
    ): float
    {
        $byPosition = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ($squadIds as $playerId) {
            $player = $playerMap[$playerId] ?? null;
            if (!$player) continue;
            $position = (int)$player['position'];
            $pred = $predictions[$playerId][$gw] ?? 0;
            $byPosition[$position][] = ['id' => $playerId, 'pred' => $pred];
        }

        foreach ($byPosition as &$players) {
            usort($players, fn($a, $b) => $b['pred'] <=> $a['pred']);
        }

        $starting11 = self::selectOptimalStarting11($byPosition);

        $captainPred = 0.0;
        $startingTotal = 0.0;
        foreach ($starting11 as $p) {
            $startingTotal += $p['pred'];
            if ($p['pred'] > $captainPred) {
                $captainPred = $p['pred'];
            }
        }

        $captainMultiplier = $chip === self::CHIP_TRIPLE_CAPTAIN ? 3 : 2;
        $total = $startingTotal + (($captainMultiplier - 1) * $captainPred);

        if ($chip === self::CHIP_BENCH_BOOST) {
            $benchTotal = $this->calculateBenchTotal($squadIds, $starting11, $predictions, $gw);
            $total += $benchTotal;
        }

        return $total;
    }

    /**
     * @param array<int, array{id: int, pred: float}> $starting11
     */
    private function calculateBenchTotal(array $squadIds, array $starting11, array $predictions, int $gw): float
    {
        $startingIds = array_column($starting11, 'id');
        $benchTotal = 0.0;

        foreach ($squadIds as $playerId) {
            if (in_array($playerId, $startingIds, true)) {
                continue;
            }
            $benchTotal += (float) ($predictions[$playerId][$gw] ?? 0.0);
        }

        return $benchTotal;
    }

    /**
     * @param array<string, int> $chipPlan
     */
    private function chipForGameweek(array $chipPlan, int $gameweek): ?string
    {
        foreach ([self::CHIP_WILDCARD, self::CHIP_FREE_HIT, self::CHIP_BENCH_BOOST, self::CHIP_TRIPLE_CAPTAIN] as $chip) {
            if ((int) ($chipPlan[$chip] ?? 0) === $gameweek) {
                return $chip;
            }
        }

        return self::CHIP_NONE;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateChipSquadChildren(
        array $state,
        int $gw,
        array $predictions,
        array $playerMap,
        array $gameweeks,
        string $chip
    ): array {
        $chipSquads = $this->optimizeChipSquads(
            $state['squad_ids'],
            (float) $state['bank'],
            $gw,
            $gameweeks,
            $predictions,
            $playerMap,
            $chip,
            $this->beamWidth >= 30 ? 3 : 2
        );

        if (empty($chipSquads)) {
            $chipSquads[] = [
                'squad_ids' => $state['squad_ids'],
                'bank' => (float) $state['bank'],
            ];
        }

        $children = [];
        foreach ($chipSquads as $chipSquad) {
            $chipSquadIds = $chipSquad['squad_ids'];
            $chipBank = (float) $chipSquad['bank'];

            $gwScore = $this->evaluateSquad($chipSquadIds, $predictions, $gw, $playerMap, $chip);

            $newState = $state;
            $newState['score'] += $gwScore;
            $newState['display_score'] += $gwScore;
            $newState['ft'] = $state['ft']; // FT carries unchanged through WC/FH in this model.

            if ($chip === self::CHIP_WILDCARD) {
                $newState['squad_ids'] = $chipSquadIds;
                $newState['bank'] = round($chipBank, 1);
            }

            $newState['transfers_by_gw'][$gw] = [
                'action' => 'transfer',
                'ft_available' => $state['ft'],
                'ft_after' => $state['ft'],
                'moves' => [],
                'hit_cost' => 0,
                'gw_score' => round($gwScore, 1),
                // For FH, expose the temporary squad in this GW row even though state reverts next GW.
                'squad_ids' => $chipSquadIds,
                'bank' => round($chipBank, 1),
                'chip_played' => $chip,
            ];

            $children[] = $newState;
        }

        return $children;
    }

    /**
     * Optimize WC/FH squad choices under budget, position, and team constraints.
     *
     * @return array<int, array{squad_ids: int[], bank: float}>
     */
    private function optimizeChipSquads(
        array $currentSquadIds,
        float $currentBank,
        int $gw,
        array $gameweeks,
        array $predictions,
        array $playerMap,
        string $chip,
        int $limit
    ): array {
        $budgetUnits = (int) round($currentBank * 10);
        foreach ($currentSquadIds as $id) {
            $budgetUnits += (int) ($playerMap[$id]['now_cost'] ?? 0);
        }

        $remainingGws = array_values(array_filter($gameweeks, fn($x) => $x >= $gw));
        $objective = [];
        foreach ($playerMap as $playerId => $player) {
            if (($player['chance_of_playing'] ?? null) === 0) {
                continue;
            }

            $score = 0.0;
            if ($chip === self::CHIP_WILDCARD) {
                foreach ($remainingGws as $g) {
                    $score += (float) ($predictions[$playerId][$g] ?? 0.0);
                }
            } else {
                $score = (float) ($predictions[$playerId][$gw] ?? 0.0);
            }
            $objective[$playerId] = $score;
        }

        $pools = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($playerMap as $playerId => $player) {
            $pos = (int) ($player['position'] ?? 0);
            if (!isset($pools[$pos])) {
                continue;
            }
            if (($player['chance_of_playing'] ?? null) === 0) {
                continue;
            }

            $pools[$pos][] = [
                'id' => $playerId,
                'team' => (int) ($player['club_id'] ?? 0),
                'cost' => (int) ($player['now_cost'] ?? 0),
                'score' => (float) ($objective[$playerId] ?? 0.0),
            ];
        }

        $poolLimits = [1 => 16, 2 => 30, 3 => 30, 4 => 20];
        foreach ($pools as $pos => &$pool) {
            usort($pool, fn($a, $b) => $b['score'] <=> $a['score']);
            $pool = array_slice($pool, 0, $poolLimits[$pos] ?? 20);
        }
        unset($pool);

        $slots = array_merge([1, 1], array_fill(0, 5, 2), array_fill(0, 5, 3), array_fill(0, 3, 4));
        $minCostByPos = [];
        foreach ([1, 2, 3, 4] as $pos) {
            $minCostByPos[$pos] = empty($pools[$pos])
                ? PHP_INT_MAX
                : min(array_column($pools[$pos], 'cost'));
        }

        $remainingMinCost = [];
        $slotCount = count($slots);
        $remainingMinCost[$slotCount] = 0;
        for ($i = $slotCount - 1; $i >= 0; $i--) {
            $slotPos = $slots[$i];
            $remainingMinCost[$i] = $remainingMinCost[$i + 1] + ($minCostByPos[$slotPos] ?? PHP_INT_MAX);
        }

        $states = [[
            'ids' => [],
            'id_set' => [],
            'cost' => 0,
            'score' => 0.0,
            'team_counts' => [],
        ]];

        $beamSize = $this->beamWidth >= 30 ? 500 : 250;

        for ($idx = 0; $idx < $slotCount; $idx++) {
            $pos = $slots[$idx];
            $nextStates = [];
            $bestByHash = [];

            foreach ($states as $state) {
                foreach ($pools[$pos] as $candidate) {
                    $pid = $candidate['id'];
                    $team = $candidate['team'];
                    $cost = $candidate['cost'];

                    if (isset($state['id_set'][$pid])) {
                        continue;
                    }

                    $teamCount = (int) ($state['team_counts'][$team] ?? 0);
                    if ($teamCount >= 3) {
                        continue;
                    }

                    $newCost = $state['cost'] + $cost;
                    if ($newCost > $budgetUnits) {
                        continue;
                    }

                    if ($newCost + $remainingMinCost[$idx + 1] > $budgetUnits) {
                        continue;
                    }

                    $newIds = $state['ids'];
                    $newIds[] = $pid;
                    sort($newIds);
                    $hash = implode(',', $newIds);
                    $newTeamCounts = $state['team_counts'];
                    $newTeamCounts[$team] = $teamCount + 1;

                    $newState = [
                        'ids' => $newIds,
                        'id_set' => $state['id_set'] + [$pid => true],
                        'cost' => $newCost,
                        'score' => $state['score'] + $candidate['score'],
                        'team_counts' => $newTeamCounts,
                    ];

                    if (!isset($bestByHash[$hash]) || $newState['score'] > $bestByHash[$hash]['score']) {
                        $bestByHash[$hash] = $newState;
                    }
                }
            }

            $nextStates = array_values($bestByHash);
            usort($nextStates, fn($a, $b) => $b['score'] <=> $a['score']);
            $states = array_slice($nextStates, 0, $beamSize);
        }

        $results = [];
        foreach (array_slice($states, 0, max(1, $limit)) as $state) {
            $ids = $state['ids'];
            sort($ids);
            $results[] = [
                'squad_ids' => $ids,
                'bank' => round(($budgetUnits - (int) $state['cost']) / 10, 1),
            ];
        }

        return $results;
    }

    /**
     * Select optimal starting 11.
     * Formation constraints: 1 GK, 3-5 DEF, 2-5 MID, 1-3 FWD, total 11
     */
    public static function selectOptimalStarting11(array $byPosition): array
    {
        $starting = [];

        // Must have 1 GK
        if (!empty($byPosition[1])) {
            $starting[] = $byPosition[1][0];
        }

        $defs = array_slice($byPosition[2], 0, 5);
        $mids = array_slice($byPosition[3], 0, 5);
        $fwds = array_slice($byPosition[4], 0, 3);

        // Minimums: 3 DEF, 2 MID, 1 FWD
        $selectedDefs = array_slice($defs, 0, 3);
        $selectedMids = array_slice($mids, 0, 2);
        $selectedFwds = array_slice($fwds, 0, 1);

        // 4 flex spots
        $flexSpots = 4;
        $flexPool = array_merge(
            array_slice($defs, 3),
            array_slice($mids, 2),
            array_slice($fwds, 1),
        );
        usort($flexPool, fn($a, $b) => $b['pred'] <=> $a['pred']);

        $defCount = 3;
        $midCount = 2;
        $fwdCount = 1;

        foreach ($flexPool as $player) {
            if ($flexSpots <= 0) break;

            $pos = null;
            foreach ($byPosition as $position => $players) {
                foreach ($players as $p) {
                    if ($p['id'] === $player['id']) {
                        $pos = $position;
                        break 2;
                    }
                }
            }

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
     * Build candidate pools per position.
     */
    private function buildCandidatePools(
        array $squadIds,
        array $predictions,
        array $gameweeks,
        array $playerMap,
    ): array {
        $pools = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ($playerMap as $playerId => $player) {
            if (in_array($playerId, $squadIds)) continue;

            $position = (int)$player['position'];
            $chanceOfPlaying = $player['chance_of_playing'] ?? null;
            if ($chanceOfPlaying === 0) continue;

            $totalPred = 0.0;
            foreach ($gameweeks as $gw) {
                $totalPred += $predictions[$playerId][$gw] ?? 0;
            }

            if ($totalPred < 5.0 && count($gameweeks) > 1) continue;

            $pools[$position][] = [
                'id' => $playerId,
                'cost' => (int)$player['now_cost'],
                'total_pred' => $totalPred,
                'team' => (int)($player['club_id'] ?? 0),
            ];
        }

        // Sort by total predicted and keep top N
        foreach ($pools as $pos => &$pool) {
            usort($pool, fn($a, $b) => $b['total_pred'] <=> $a['total_pred']);
            $pool = array_slice($pool, 0, $this->candidatesPerPos);
        }

        // Per-GW overlay: add top 3 per position per GW (catches DGW spikes)
        foreach ($gameweeks as $gw) {
            $byPosGw = [1 => [], 2 => [], 3 => [], 4 => []];
            foreach ($playerMap as $playerId => $player) {
                if (in_array($playerId, $squadIds)) continue;
                $position = (int)$player['position'];
                $gwPred = $predictions[$playerId][$gw] ?? 0;
                if ($gwPred < 3.0) continue;
                $byPosGw[$position][] = [
                    'id' => $playerId,
                    'cost' => (int)$player['now_cost'],
                    'total_pred' => 0, // Not used for dedup
                    'team' => (int)($player['club_id'] ?? 0),
                    'gw_pred' => $gwPred,
                ];
            }

            foreach ($byPosGw as $pos => $gwPool) {
                usort($gwPool, fn($a, $b) => $b['gw_pred'] <=> $a['gw_pred']);
                $top3 = array_slice($gwPool, 0, 3);
                $existingIds = array_column($pools[$pos], 'id');
                foreach ($top3 as $candidate) {
                    if (!in_array($candidate['id'], $existingIds)) {
                        // Calculate total pred for this candidate
                        $totalPred = 0;
                        foreach ($gameweeks as $g) {
                            $totalPred += $predictions[$candidate['id']][$g] ?? 0;
                        }
                        $candidate['total_pred'] = $totalPred;
                        unset($candidate['gw_pred']);
                        $pools[$pos][] = $candidate;
                    }
                }
            }
        }

        return $pools;
    }

    /**
     * Rank squad players by weakness (best transfer-out candidates first).
     */
    private function rankOutPlayers(
        array $squadIds,
        array $predictions,
        int $currentGw,
        array $gameweeks,
        array $playerMap,
    ): array {
        $scores = [];
        $remainingGws = array_filter($gameweeks, fn($g) => $g >= $currentGw);

        foreach ($squadIds as $playerId) {
            $total = 0;
            foreach ($remainingGws as $gw) {
                $total += $predictions[$playerId][$gw] ?? 0;
            }
            $scores[$playerId] = $total;
        }

        asort($scores); // Ascending — worst first
        return array_keys($scores);
    }

    /**
     * Check team limit for a single transfer (max 3 per team).
     */
    private function checkTeamLimit(array $squad, int $outId, int $inId, array $playerMap): bool
    {
        $inTeam = (int)($playerMap[$inId]['club_id'] ?? 0);
        $outTeam = (int)($playerMap[$outId]['club_id'] ?? 0);

        $teamCount = 0;
        foreach ($squad as $pid) {
            if ($pid === $outId) continue; // Being removed
            if ((int)($playerMap[$pid]['club_id'] ?? 0) === $inTeam) {
                $teamCount++;
            }
        }

        return $teamCount < 3;
    }

    /**
     * Check team limit for entire squad (max 3 per team).
     */
    private function checkTeamLimitMulti(array $squad, array $playerMap): bool
    {
        $teamCounts = [];
        foreach ($squad as $pid) {
            $team = (int)($playerMap[$pid]['club_id'] ?? 0);
            $teamCounts[$team] = ($teamCounts[$team] ?? 0) + 1;
            if ($teamCounts[$team] > 3) return false;
        }
        return true;
    }

    /**
     * Deduplicate states by hashing squad IDs + FT count.
     */
    private function deduplicate(array $states): array
    {
        $seen = [];
        $unique = [];

        foreach ($states as $state) {
            $ids = $state['squad_ids'];
            sort($ids);
            $hash = implode(',', $ids) . ':' . $state['ft'];

            if (!isset($seen[$hash]) || $state['score'] > $seen[$hash]['score']) {
                $seen[$hash] = $state;
            }
        }

        return array_values($seen);
    }

    /**
     * Select top N diverse paths from beam.
     * Two paths are "similar" if their combined transfer moves differ by fewer than 2.
     */
    private function selectDiversePaths(array $beam, int $n): array
    {
        if (count($beam) <= 1) return $beam;

        $selected = [$beam[0]];

        for ($i = 1; $i < count($beam) && count($selected) < $n; $i++) {
            $candidate = $beam[$i];
            $isDiverse = true;

            foreach ($selected as $existing) {
                if ($this->pathsSimilar($existing, $candidate)) {
                    $isDiverse = false;
                    break;
                }
            }

            if ($isDiverse) {
                $selected[] = $candidate;
            }
        }

        // If we couldn't find enough diverse paths, fill with best remaining
        if (count($selected) < $n) {
            for ($i = 1; $i < count($beam) && count($selected) < $n; $i++) {
                $candidate = $beam[$i];
                $alreadySelected = false;
                foreach ($selected as $s) {
                    if ($s === $candidate) {
                        $alreadySelected = true;
                        break;
                    }
                }
                if (!$alreadySelected) {
                    $selected[] = $candidate;
                }
            }
        }

        return $selected;
    }

    /**
     * Check if two paths are too similar (share too many transfer moves).
     */
    private function pathsSimilar(array $pathA, array $pathB): bool
    {
        $movesA = $this->extractMoveSet($pathA);
        $movesB = $this->extractMoveSet($pathB);

        if (empty($movesA) && empty($movesB)) return true;
        if (empty($movesA) || empty($movesB)) return false;

        $shared = count(array_intersect($movesA, $movesB));
        $total = count(array_unique(array_merge($movesA, $movesB)));
        $different = $total - $shared;

        return $different < 2;
    }

    /**
     * Extract set of move keys from a path.
     */
    private function extractMoveSet(array $path): array
    {
        $moves = [];
        foreach ($path['transfers_by_gw'] as $gwData) {
            foreach ($gwData['moves'] as $m) {
                $moves[] = $m['out_id'] . ':' . $m['in_id'];
            }
        }
        sort($moves);
        return $moves;
    }
}
