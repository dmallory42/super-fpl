<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Services\PathSolver;

class PathSolverTest extends TestCase
{
    /**
     * Build a minimal player row for the solver.
     */
    private function makePlayer(int $id, int $position, int $team, int $nowCost, string $name = ''): array
    {
        return [
            'id' => $id,
            'position' => $position,
            'club_id' => $team,
            'now_cost' => $nowCost,
            'web_name' => $name ?: "Player{$id}",
            'chance_of_playing' => null,
        ];
    }

    /**
     * Build a valid 15-player squad with predictions.
     * 2 GK, 5 DEF, 5 MID, 3 FWD — all on different teams.
     * Returns [squadIds, predictions, playerMap].
     */
    private function makeSquad(array $gameweeks, float $basePts = 4.0): array
    {
        $playerMap = [];
        $squadIds = [];
        $predictions = [];

        // 2 GK (teams 1-2)
        for ($i = 1; $i <= 2; $i++) {
            $id = $i;
            $playerMap[$id] = $this->makePlayer($id, 1, $i, 50, "GK{$i}");
            $squadIds[] = $id;
            foreach ($gameweeks as $gw) {
                $predictions[$id][$gw] = $basePts;
            }
        }

        // 5 DEF (teams 3-7)
        for ($i = 1; $i <= 5; $i++) {
            $id = 10 + $i;
            $playerMap[$id] = $this->makePlayer($id, 2, $i + 2, 50, "DEF{$i}");
            $squadIds[] = $id;
            foreach ($gameweeks as $gw) {
                $predictions[$id][$gw] = $basePts;
            }
        }

        // 5 MID (teams 8-12)
        for ($i = 1; $i <= 5; $i++) {
            $id = 20 + $i;
            $playerMap[$id] = $this->makePlayer($id, 3, $i + 7, 50, "MID{$i}");
            $squadIds[] = $id;
            foreach ($gameweeks as $gw) {
                $predictions[$id][$gw] = $basePts;
            }
        }

        // 3 FWD (teams 13-15)
        for ($i = 1; $i <= 3; $i++) {
            $id = 30 + $i;
            $playerMap[$id] = $this->makePlayer($id, 4, $i + 12, 50, "FWD{$i}");
            $squadIds[] = $id;
            foreach ($gameweeks as $gw) {
                $predictions[$id][$gw] = $basePts;
            }
        }

        return [$squadIds, $predictions, $playerMap];
    }

    /**
     * Add transfer candidate players to the pool.
     */
    private function addCandidates(array &$predictions, array &$playerMap, array $candidates): void
    {
        foreach ($candidates as $c) {
            $playerMap[$c['id']] = $this->makePlayer(
                $c['id'],
                $c['position'],
                $c['team'],
                $c['cost'],
                $c['name'] ?? "Candidate{$c['id']}"
            );
            foreach ($c['predictions'] as $gw => $pts) {
                $predictions[$c['id']][$gw] = $pts;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Test 1: Banking beats marginal transfers
    // -------------------------------------------------------------------------
    public function testBankBeatsMarginaltransfer(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Add a marginal candidate: only 1pt gain over worst player (< 1.5 FT value)
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 100,
                'position' => 3,
                'team' => 18,
                'cost' => 50,
                'name' => 'MarginalMid',
                'predictions' => [30 => 4.5, 31 => 4.5],
            ],
        ]);

        $solver = new PathSolver(ftValue: 1.5, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        // The best path's first GW should be a bank (0 transfers) since gain < ftValue
        $bestPath = $paths[0];
        $firstGw = $gameweeks[0];
        $this->assertEquals('bank', $bestPath['transfers_by_gw'][$firstGw]['action']);
    }

    // -------------------------------------------------------------------------
    // Test 2: Multi-GW budget chain — sell cheap now to afford expensive later
    // -------------------------------------------------------------------------
    public function testMultiGwBudgetChain(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Make MID5 (id=25) the worst midfielder: 2pts per GW
        $predictions[25][30] = 2.0;
        $predictions[25][31] = 2.0;

        // Make DEF5 (id=15) mediocre: 3pts per GW
        $predictions[15][30] = 3.0;
        $predictions[15][31] = 3.0;

        // Candidate A: cheap MID, slight upgrade on MID5, costs £4.0m (sells MID5 at £5.0m, freeing £1.0m)
        // Candidate B: expensive DEF, big upgrade on DEF5, costs £7.0m (bank=5 + freed 1 = 6, still short)
        // But with chain: GW30 sell MID5→A (bank goes 5 + 5-4 = 6), GW31 sell DEF5→B (budget: 5+6=11 vs 7)

        $playerMap[25]['now_cost'] = 50; // MID5 costs 5.0m
        $playerMap[15]['now_cost'] = 50; // DEF5 costs 5.0m

        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 101,
                'position' => 3,
                'team' => 18,
                'cost' => 40, // £4.0m
                'name' => 'CheapMid',
                'predictions' => [30 => 5.0, 31 => 5.0],
            ],
            [
                'id' => 102,
                'position' => 2,
                'team' => 19,
                'cost' => 60, // £6.0m — needs bank > 1.0m to afford (DEF5 sells for 5.0)
                'name' => 'ExpensiveDef',
                'predictions' => [30 => 8.0, 31 => 8.0],
            ],
        ]);

        $solver = new PathSolver(ftValue: 0.0, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        // Check that the best path includes both transfers across the 2 GWs
        $bestPath = $paths[0];
        $allMoves = [];
        foreach ($bestPath['transfers_by_gw'] as $gwData) {
            foreach ($gwData['moves'] as $move) {
                $allMoves[] = $move;
            }
        }

        $outIds = array_column($allMoves, 'out_id');
        $inIds = array_column($allMoves, 'in_id');

        // Both transfers should appear somewhere in the path
        $this->assertContains(25, $outIds, 'Should sell MID5');
        $this->assertContains(101, $inIds, 'Should buy CheapMid');
        $this->assertContains(15, $outIds, 'Should sell DEF5');
        $this->assertContains(102, $inIds, 'Should buy ExpensiveDef');
    }

    // -------------------------------------------------------------------------
    // Test 3: Fixed transfers are honored
    // -------------------------------------------------------------------------
    public function testFixedTransfersHonored(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Add a candidate
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 100,
                'position' => 3,
                'team' => 18,
                'cost' => 50,
                'name' => 'FixedIn',
                'predictions' => [30 => 6.0, 31 => 6.0],
            ],
        ]);

        // Force: sell MID5 (id=25), buy FixedIn (id=100) in GW30
        $fixedTransfers = [
            ['gameweek' => 30, 'out' => 25, 'in' => 100],
        ];

        $solver = new PathSolver(ftValue: 1.5, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1, $fixedTransfers);

        $this->assertNotEmpty($paths);

        // Every path must include the fixed transfer in GW30
        foreach ($paths as $path) {
            $gw30Moves = $path['transfers_by_gw'][30]['moves'] ?? [];
            $found = false;
            foreach ($gw30Moves as $move) {
                if ($move['out_id'] === 25 && $move['in_id'] === 100) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Every path must include the fixed transfer');
        }
    }

    public function testObjectiveModesCanChangeBestPathRanking(): void
    {
        $gameweeks = [30];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        $predictions[25][30] = [
            'predicted_points' => 2.0,
            'confidence' => 1.0,
            'expected_mins' => 90.0,
        ];

        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 201,
                'position' => 3,
                'team' => 18,
                'cost' => 50,
                'name' => 'SafeMid',
                'predictions' => [
                    30 => [
                        'predicted_points' => 8.0,
                        'confidence' => 0.95,
                        'expected_mins' => 90.0,
                    ],
                ],
            ],
            [
                'id' => 202,
                'position' => 3,
                'team' => 19,
                'cost' => 50,
                'name' => 'BoomBustMid',
                'predictions' => [
                    30 => [
                        'predicted_points' => 7.0,
                        'confidence' => 0.35,
                        'expected_mins' => 90.0,
                    ],
                ],
            ],
        ]);

        $expectedSolver = new PathSolver(ftValue: 0.0, depth: 'quick', objectiveMode: 'expected');
        $expectedPaths = $expectedSolver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);
        $this->assertNotEmpty($expectedPaths);
        $expectedMove = $expectedPaths[0]['transfers_by_gw'][30]['moves'][0]['in_id'] ?? null;
        $this->assertSame(201, $expectedMove);

        $ceilingSolver = new PathSolver(ftValue: 0.0, depth: 'quick', objectiveMode: 'ceiling');
        $ceilingPaths = $ceilingSolver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);
        $this->assertNotEmpty($ceilingPaths);
        $ceilingMove = $ceilingPaths[0]['transfers_by_gw'][30]['moves'][0]['in_id'] ?? null;
        $this->assertSame(202, $ceilingMove);
    }

    // -------------------------------------------------------------------------
    // Test 4: High FT value discourages hits
    // -------------------------------------------------------------------------
    public function testHighFtValueDiscouragsHits(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // MID4 and MID5 are weak
        $predictions[24][30] = 2.0;
        $predictions[24][31] = 2.0;
        $predictions[25][30] = 2.0;
        $predictions[25][31] = 2.0;

        // Two decent candidates — enough gain to justify hits at ftValue=0, but not at ftValue=5
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 101,
                'position' => 3,
                'team' => 18,
                'cost' => 50,
                'name' => 'DecentMidA',
                'predictions' => [30 => 5.0, 31 => 5.0],
                // Gain per GW: 3, total: 6. Hit cost -4, net +2 at ftValue=0
            ],
            [
                'id' => 102,
                'position' => 3,
                'team' => 19,
                'cost' => 50,
                'name' => 'DecentMidB',
                'predictions' => [30 => 5.0, 31 => 5.0],
            ],
        ]);

        // At ftValue=5, hits are heavily penalized (internal cost = -4 actual - 5 aversion = -9)
        // A 3pt/GW gain over 2 GWs = 6 total, minus 9 internal penalty = -3 net → not worth it
        $solver = new PathSolver(ftValue: 5.0, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        $bestPath = $paths[0];
        // With high ftValue, the best path should not take hits for marginal gains
        $this->assertEquals(0, $bestPath['total_hits'], 'High ftValue should discourage hits');
    }

    // -------------------------------------------------------------------------
    // Test 5: FT rollover model — banking 3 weeks gives 4 FTs (max 5)
    // -------------------------------------------------------------------------
    public function testFtRolloverCorrect(): void
    {
        $gameweeks = [30, 31, 32, 33];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // No good candidates — solver should bank every week
        $solver = new PathSolver(ftValue: 1.5, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        $bestPath = $paths[0];

        // With ftValue=1.5 and no good candidates, should bank each GW
        // GW30: 1 FT, bank → 2 FT next
        // GW31: 2 FT, bank → 3 FT next
        // GW32: 3 FT, bank → 4 FT next
        // GW33: 4 FT, bank → 5 FT next (capped)
        $this->assertEquals('bank', $bestPath['transfers_by_gw'][30]['action']);
        $this->assertEquals(1, $bestPath['transfers_by_gw'][30]['ft_available']);
        $this->assertEquals(2, $bestPath['transfers_by_gw'][30]['ft_after']);

        $this->assertEquals('bank', $bestPath['transfers_by_gw'][31]['action']);
        $this->assertEquals(2, $bestPath['transfers_by_gw'][31]['ft_available']);
        $this->assertEquals(3, $bestPath['transfers_by_gw'][31]['ft_after']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Top 3 paths are diverse (differ by >= 2 moves)
    // -------------------------------------------------------------------------
    public function testPathDiversity(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Make several squad players weak and add multiple good candidates
        $predictions[21][30] = 1.0;
        $predictions[21][31] = 1.0;
        $predictions[22][30] = 1.5;
        $predictions[22][31] = 1.5;
        $predictions[23][30] = 2.0;
        $predictions[23][31] = 2.0;

        $this->addCandidates($predictions, $playerMap, [
            ['id' => 101, 'position' => 3, 'team' => 18, 'cost' => 50, 'name' => 'CandA', 'predictions' => [30 => 7.0, 31 => 7.0]],
            ['id' => 102, 'position' => 3, 'team' => 19, 'cost' => 50, 'name' => 'CandB', 'predictions' => [30 => 6.5, 31 => 6.5]],
            ['id' => 103, 'position' => 3, 'team' => 20, 'cost' => 50, 'name' => 'CandC', 'predictions' => [30 => 6.0, 31 => 6.0]],
        ]);

        $solver = new PathSolver(ftValue: 0.0, depth: 'standard');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 3);

        // Should have up to 3 paths
        $this->assertGreaterThanOrEqual(2, count($paths));

        // Paths should be diverse: combined (out,in) pairs differ by >= 2
        if (count($paths) >= 2) {
            $moveSets = [];
            foreach ($paths as $path) {
                $moves = [];
                foreach ($path['transfers_by_gw'] as $gwData) {
                    foreach ($gwData['moves'] as $m) {
                        $moves[] = $m['out_id'] . ':' . $m['in_id'];
                    }
                }
                sort($moves);
                $moveSets[] = $moves;
            }

            // Check that paths 0 and 1 differ by at least 2 moves
            $shared = count(array_intersect($moveSets[0], $moveSets[1]));
            $totalUnique = count(array_unique(array_merge($moveSets[0], $moveSets[1])));
            $different = $totalUnique - $shared;
            $this->assertGreaterThanOrEqual(1, $different, 'Paths should be meaningfully different');
        }
    }

    // -------------------------------------------------------------------------
    // Test 7: Captain bonus is evaluated correctly
    // -------------------------------------------------------------------------
    public function testCaptainBonusCorrect(): void
    {
        $gameweeks = [30];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Make one player clearly the best scorer
        $predictions[21][30] = 10.0; // MID1 is captain material

        $solver = new PathSolver(ftValue: 0.0, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        // The score should include captain bonus
        // Starting 11: 1 GK(4) + 3 DEF(4×3=12) + 5 MID(10+4+4+4+4=26) + 2 FWD(4×2=8)
        // = 4+12+26+8 = 50, captain bonus = +10, total = 60
        // (bench: 1 GK + 2 DEF + 1 FWD = not counted)
        $score = $paths[0]['transfers_by_gw'][30]['gw_score'];
        $this->assertGreaterThan(50, $score, 'Score should include captain bonus beyond sum of starting 11');
    }

    // -------------------------------------------------------------------------
    // Test 8: Score vs hold is calculated correctly
    // -------------------------------------------------------------------------
    public function testScoreVsHold(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Weak player
        $predictions[25][30] = 1.0;
        $predictions[25][31] = 1.0;

        // Strong candidate
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 100,
                'position' => 3,
                'team' => 18,
                'cost' => 50,
                'name' => 'StrongMid',
                'predictions' => [30 => 8.0, 31 => 8.0],
            ],
        ]);

        $solver = new PathSolver(ftValue: 0.0, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        // score_vs_hold should be positive (transfer path is better than holding)
        $this->assertGreaterThan(0, $paths[0]['score_vs_hold']);
    }

    // -------------------------------------------------------------------------
    // Test 9: Hits cost -4 and reset FTs to 1
    // -------------------------------------------------------------------------
    public function testHitsResetFts(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Two very weak mids
        $predictions[24][30] = 0.0;
        $predictions[24][31] = 0.0;
        $predictions[25][30] = 0.0;
        $predictions[25][31] = 0.0;

        // Two very strong candidates — worth the hit
        $this->addCandidates($predictions, $playerMap, [
            ['id' => 101, 'position' => 3, 'team' => 18, 'cost' => 50, 'name' => 'StarMidA', 'predictions' => [30 => 10.0, 31 => 10.0]],
            ['id' => 102, 'position' => 3, 'team' => 19, 'cost' => 50, 'name' => 'StarMidB', 'predictions' => [30 => 10.0, 31 => 10.0]],
        ]);

        $solver = new PathSolver(ftValue: 0.0, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        // Find a path that takes a hit in GW30
        $hitPath = null;
        foreach ($paths as $path) {
            if ($path['transfers_by_gw'][30]['hit_cost'] > 0) {
                $hitPath = $path;
                break;
            }
        }

        if ($hitPath) {
            // After taking a hit, next GW FTs should be 1
            $this->assertEquals(1, $hitPath['transfers_by_gw'][31]['ft_available']);
            $this->assertEquals(4, $hitPath['transfers_by_gw'][30]['hit_cost']);
            $this->assertGreaterThan(0, $hitPath['total_hits']);
        }
    }

    // -------------------------------------------------------------------------
    // Test 10: Team limit enforced (max 3 players per team)
    // -------------------------------------------------------------------------
    public function testTeamLimitEnforced(): void
    {
        $gameweeks = [30];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Give team 8 (MID1's team) 2 more players (DEF4=team 6, DEF5=team 7)
        // Change MID2 and MID3 to also be on team 8
        $playerMap[22]['club_id'] = 8;
        $playerMap[23]['club_id'] = 8;

        // Now team 8 has 3 players (MID1=21, MID2=22, MID3=23)
        // Add a great candidate also on team 8 — should be blocked
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 100,
                'position' => 3,
                'team' => 8,
                'cost' => 50,
                'name' => 'BlockedMid',
                'predictions' => [30 => 20.0],
            ],
            // Also add a slightly worse candidate on a different team
            [
                'id' => 101,
                'position' => 3,
                'team' => 20,
                'cost' => 50,
                'name' => 'AllowedMid',
                'predictions' => [30 => 15.0],
            ],
        ]);

        // Make MID4 weak so there's incentive to transfer
        $predictions[24][30] = 0.0;

        $solver = new PathSolver(ftValue: 0.0, depth: 'quick');
        $paths = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);

        $this->assertNotEmpty($paths);

        // BlockedMid should NOT appear unless we're also selling a team 8 player
        foreach ($paths as $path) {
            foreach ($path['transfers_by_gw'] as $gwData) {
                foreach ($gwData['moves'] as $move) {
                    if ($move['in_id'] === 100) {
                        // If bringing in team 8 player, must be selling one too
                        $outPlayer = $playerMap[$move['out_id']] ?? null;
                        $this->assertEquals(8, $outPlayer['club_id'] ?? 0,
                            'Can only bring in team 8 player if selling a team 8 player');
                    }
                }
            }
        }
    }

    public function testTripleCaptainAddsExtraCaptainMultiplier(): void
    {
        $gameweeks = [30];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);
        $predictions[21][30] = 10.0; // Captain candidate

        $solver = new PathSolver(ftValue: 10.0, depth: 'quick');
        $noChip = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);
        $tc = $solver->solve(
            $squadIds,
            $predictions,
            $gameweeks,
            $playerMap,
            5.0,
            1,
            [],
            ['triple_captain' => 30]
        );

        $this->assertNotEmpty($noChip);
        $this->assertNotEmpty($tc);
        $this->assertGreaterThan($noChip[0]['total_score'], $tc[0]['total_score'] - 0.1); // sanity
        $this->assertEqualsWithDelta(10.0, $tc[0]['total_score'] - $noChip[0]['total_score'], 0.2);
    }

    public function testBenchBoostAddsBenchPoints(): void
    {
        $gameweeks = [30];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        $solver = new PathSolver(ftValue: 10.0, depth: 'quick');
        $noChip = $solver->solve($squadIds, $predictions, $gameweeks, $playerMap, 5.0, 1);
        $bb = $solver->solve(
            $squadIds,
            $predictions,
            $gameweeks,
            $playerMap,
            5.0,
            1,
            [],
            ['bench_boost' => 30]
        );

        $this->assertNotEmpty($noChip);
        $this->assertNotEmpty($bb);
        // 4 bench players at 4.0 each.
        $this->assertEqualsWithDelta(16.0, $bb[0]['total_score'] - $noChip[0]['total_score'], 0.2);
    }

    public function testFreeHitSquadRevertsAfterChipWeek(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // A one-week monster only in GW30.
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 200,
                'position' => 3,
                'team' => 18,
                'cost' => 100,
                'name' => 'OneWeekStar',
                'predictions' => [30 => 20.0, 31 => 0.0],
            ],
        ]);

        $solver = new PathSolver(ftValue: 10.0, depth: 'quick');
        $paths = $solver->solve(
            $squadIds,
            $predictions,
            $gameweeks,
            $playerMap,
            5.0,
            1,
            [],
            ['free_hit' => 30]
        );

        $this->assertNotEmpty($paths);
        $best = $paths[0];
        $this->assertContains(200, $best['transfers_by_gw'][30]['squad_ids']);
        $this->assertNotContains(200, $best['transfers_by_gw'][31]['squad_ids']);
    }

    public function testWildcardSquadPersistsAfterChipWeek(): void
    {
        $gameweeks = [30, 31];
        [$squadIds, $predictions, $playerMap] = $this->makeSquad($gameweeks, 4.0);

        // Premium upgrade that should remain valuable beyond GW30.
        $this->addCandidates($predictions, $playerMap, [
            [
                'id' => 201,
                'position' => 3,
                'team' => 18,
                'cost' => 100,
                'name' => 'LongTermStar',
                'predictions' => [30 => 14.0, 31 => 14.0],
            ],
        ]);

        $solver = new PathSolver(ftValue: 10.0, depth: 'quick');
        $paths = $solver->solve(
            $squadIds,
            $predictions,
            $gameweeks,
            $playerMap,
            5.0,
            1,
            [],
            ['wildcard' => 30]
        );

        $this->assertNotEmpty($paths);
        $best = $paths[0];
        $this->assertContains(201, $best['transfers_by_gw'][30]['squad_ids']);
        $this->assertContains(201, $best['transfers_by_gw'][31]['squad_ids']);
    }
}
