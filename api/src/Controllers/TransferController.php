<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use InvalidArgumentException;
use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\GameweekService;
use SuperFPL\Api\Services\PredictionService;
use SuperFPL\Api\Services\TransferOptimizerService;
use SuperFPL\Api\Services\TransferService;
use SuperFPL\FplClient\FplClient;

#[Controller]
class TransferController extends LegacyController
{
    public function __construct(
        Database $db,
        Config $config,
        private readonly FplClient $fplClient
    ) {
        parent::__construct($db, $config);
    }

    #[Route('/transfers/suggest', method: 'GET')]
    public function suggest(Request $request): Response
    {
        $managerId = $request->query('manager');
        if ($managerId === null) {
            return Response::json(['error' => 'Missing manager parameter'], 400);
        }

        $gameweek = $request->query('gw');
        $transfers = $request->query('transfers');
        $service = new TransferService($this->db, $this->fplClient);

        return Response::json($service->getSuggestions(
            (int) $managerId,
            $gameweek !== null ? (int) $gameweek : $this->currentGameweek(),
            $transfers !== null ? (int) $transfers : 1
        ));
    }

    #[Route('/transfers/simulate', method: 'GET')]
    public function simulate(Request $request): Response
    {
        $managerId = $request->query('manager');
        $out = $request->query('out');
        $in = $request->query('in');

        if ($managerId === null || $out === null || $in === null) {
            return Response::json(['error' => 'Missing parameters: manager, out, in required'], 400);
        }

        $gameweek = $request->query('gw');
        $service = new TransferService($this->db, $this->fplClient);

        return Response::json($service->simulateTransfer(
            (int) $managerId,
            $gameweek !== null ? (int) $gameweek : $this->currentGameweek(),
            (int) $out,
            (int) $in
        ));
    }

    #[Route('/transfers/targets', method: 'GET')]
    public function targets(Request $request): Response
    {
        $gameweek = $request->query('gw');
        $position = $request->query('position');
        $maxPrice = $request->query('max_price');

        $service = new TransferService($this->db, $this->fplClient);
        $targets = $service->getTopTargets(
            $gameweek !== null ? (int) $gameweek : $this->currentGameweek(),
            $position !== null ? (int) $position : null,
            $maxPrice !== null ? ((float) $maxPrice * 10) : null
        );

        return Response::json([
            'gameweek' => $gameweek !== null ? (int) $gameweek : $this->currentGameweek(),
            'targets' => $targets,
        ]);
    }

    #[Route('/planner/optimize', method: 'GET')]
    public function optimize(Request $request): Response
    {
        $managerId = $request->query('manager');
        if ($managerId === null) {
            return Response::json(['error' => 'Missing manager parameter'], 400);
        }

        try {
            $chipPlan = $this->parsePlannerChipPlanFromRequest($request);
            $chipAllow = $this->parsePlannerChipAllowFromRequest($request);
            $chipForbid = $this->parsePlannerChipForbidFromRequest($request);
            $fixedTransfers = $this->parsePlannerFixedTransfersFromRequest($request);
            $constraints = $this->parsePlannerConstraintsFromRequest($request);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }

        $freeTransfers = (int) ($request->query('ft') ?? 0);
        $xMinsOverrides = $this->parseXMinsOverridesFromRequest($request);
        $ftValue = max(0.0, min(5.0, (float) ($request->query('ft_value') ?? 1.5)));

        $depth = (string) ($request->query('depth') ?? 'standard');
        if (!in_array($depth, ['quick', 'standard', 'deep'], true)) {
            $depth = 'standard';
        }

        $planningHorizon = max(1, min(12, (int) ($request->query('horizon') ?? 6)));
        $objectiveMode = (string) ($request->query('objective') ?? 'expected');
        if (!in_array($objectiveMode, ['expected', 'floor', 'ceiling'], true)) {
            $objectiveMode = 'expected';
        }

        $skipSolve = (string) ($request->query('skip_solve') ?? '0') === '1';
        $chipCompare = (string) ($request->query('chip_compare') ?? '0') === '1';
        $chipMode = (string) ($request->query('chip_mode') ?? 'locked');

        $predictionService = new PredictionService($this->db);
        $gameweekService = new GameweekService($this->db);
        $optimizer = new TransferOptimizerService(
            $this->db,
            $this->fplClient,
            $predictionService,
            $gameweekService
        );

        try {
            $plan = $optimizer->getOptimalPlan(
                (int) $managerId,
                $chipPlan,
                $freeTransfers,
                $xMinsOverrides,
                $fixedTransfers,
                $ftValue,
                $depth,
                $skipSolve,
                $chipMode,
                $chipAllow,
                $chipForbid,
                $chipCompare,
                $objectiveMode,
                $constraints,
                $planningHorizon
            );

            return Response::json($plan);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }
    }

    #[Route('/planner/chips/suggest', method: 'GET')]
    public function chipSuggest(Request $request): Response
    {
        $managerId = $request->query('manager');
        if ($managerId === null) {
            return Response::json(['error' => 'Missing manager parameter'], 400);
        }

        try {
            $chipPlan = $this->parsePlannerChipPlanFromRequest($request);
            $chipAllow = $this->parsePlannerChipAllowFromRequest($request);
            $chipForbid = $this->parsePlannerChipForbidFromRequest($request);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }

        $predictionService = new PredictionService($this->db);
        $gameweekService = new GameweekService($this->db);
        $optimizer = new TransferOptimizerService(
            $this->db,
            $this->fplClient,
            $predictionService,
            $gameweekService
        );

        try {
            return Response::json($optimizer->suggestChipPlan(
                (int) $managerId,
                (int) ($request->query('ft') ?? 0),
                $chipPlan,
                $chipAllow,
                $chipForbid,
                max(1, min(12, (int) ($request->query('horizon') ?? 6)))
            ));
        } catch (\Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * @return array<int, string>
     */
    private function getValidChips(): array
    {
        return ['wildcard', 'bench_boost', 'free_hit', 'triple_captain'];
    }

    /**
     * @return mixed
     */
    private function decodeJsonQueryParam(Request $request, string $param)
    {
        $raw = $request->query($param);
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            throw new InvalidArgumentException("Invalid {$param}");
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException("Invalid JSON for {$param}");
        }
    }

    /**
     * @param mixed $value
     */
    private function parsePlannerGameweekValue(string $label, $value): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Invalid {$label}: expected integer gameweek");
        }

        $gameweek = (int) $value;
        if ($gameweek < 1 || $gameweek > 38) {
            throw new InvalidArgumentException("Invalid {$label}: expected gameweek between 1 and 38");
        }

        return $gameweek;
    }

    /**
     * @return array<string, int>
     */
    private function parsePlannerChipPlanFromRequest(Request $request): array
    {
        $chipPlan = [];
        $decodedPlan = $this->decodeJsonQueryParam($request, 'chip_plan');

        if ($decodedPlan !== null) {
            if (!is_array($decodedPlan) || array_is_list($decodedPlan)) {
                throw new InvalidArgumentException('Invalid chip_plan: expected JSON object keyed by chip name');
            }

            foreach ($decodedPlan as $chip => $week) {
                if (!is_string($chip) || !in_array($chip, $this->getValidChips(), true)) {
                    throw new InvalidArgumentException("Invalid chip_plan chip: {$chip}");
                }
                $chipPlan[$chip] = $this->parsePlannerGameweekValue("chip_plan.{$chip}", $week);
            }
        }

        $legacyChipParams = [
            'wildcard_gw' => 'wildcard',
            'bench_boost_gw' => 'bench_boost',
            'free_hit_gw' => 'free_hit',
            'triple_captain_gw' => 'triple_captain',
        ];

        foreach ($legacyChipParams as $param => $chipName) {
            $value = $request->query($param);
            if ($value === null) {
                continue;
            }

            $chipPlan[$chipName] = $this->parsePlannerGameweekValue($param, $value);
        }

        return $chipPlan;
    }

    /**
     * @return array<int, string>
     */
    private function parsePlannerChipAllowFromRequest(Request $request): array
    {
        $decoded = $this->decodeJsonQueryParam($request, 'chip_allow');
        if ($decoded === null) {
            return [];
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new InvalidArgumentException('Invalid chip_allow: expected JSON array of chip names');
        }

        $chips = [];
        foreach ($decoded as $chip) {
            if (!is_string($chip) || !in_array($chip, $this->getValidChips(), true)) {
                throw new InvalidArgumentException('Invalid chip_allow: contains unknown chip');
            }
            $chips[] = $chip;
        }

        return array_values(array_unique($chips));
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function parsePlannerChipForbidFromRequest(Request $request): array
    {
        $decoded = $this->decodeJsonQueryParam($request, 'chip_forbid');
        if ($decoded === null) {
            return [];
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException(
                'Invalid chip_forbid: expected JSON object mapping chip names to gameweek arrays'
            );
        }

        $chipForbid = [];
        foreach ($decoded as $chip => $weeks) {
            if (!is_string($chip) || !in_array($chip, $this->getValidChips(), true)) {
                throw new InvalidArgumentException("Invalid chip_forbid chip: {$chip}");
            }
            if (!is_array($weeks) || !array_is_list($weeks)) {
                throw new InvalidArgumentException("Invalid chip_forbid.{$chip}: expected array of gameweeks");
            }

            $normalized = [];
            foreach ($weeks as $index => $week) {
                $normalized[] = $this->parsePlannerGameweekValue("chip_forbid.{$chip}[{$index}]", $week);
            }

            $chipForbid[$chip] = array_values(array_unique($normalized));
        }

        return $chipForbid;
    }

    /**
     * @return array<int, array{gameweek: int, out: int, in: int}>
     */
    private function parsePlannerFixedTransfersFromRequest(Request $request): array
    {
        $decoded = $this->decodeJsonQueryParam($request, 'fixed_transfers');
        if ($decoded === null) {
            return [];
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new InvalidArgumentException('Invalid fixed_transfers: expected JSON array');
        }

        $fixedTransfers = [];
        foreach ($decoded as $index => $transfer) {
            if (!is_array($transfer)) {
                throw new InvalidArgumentException("Invalid fixed_transfers[{$index}]: expected object");
            }

            if (!array_key_exists('gameweek', $transfer) || !array_key_exists('out', $transfer) || !array_key_exists('in', $transfer)) {
                throw new InvalidArgumentException("Invalid fixed_transfers[{$index}]: missing gameweek/out/in");
            }

            if (!is_numeric($transfer['out']) || (int) $transfer['out'] <= 0) {
                throw new InvalidArgumentException("Invalid fixed_transfers[{$index}].out: expected positive integer");
            }
            if (!is_numeric($transfer['in']) || (int) $transfer['in'] <= 0) {
                throw new InvalidArgumentException("Invalid fixed_transfers[{$index}].in: expected positive integer");
            }

            $fixedTransfers[] = [
                'gameweek' => $this->parsePlannerGameweekValue("fixed_transfers[{$index}].gameweek", $transfer['gameweek']),
                'out' => (int) $transfer['out'],
                'in' => (int) $transfer['in'],
            ];
        }

        return $fixedTransfers;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePlannerConstraintsFromRequest(Request $request): array
    {
        $decoded = $this->decodeJsonQueryParam($request, 'constraints');
        if ($decoded === null) {
            return [];
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Invalid constraints: expected JSON object');
        }

        $allowedKeys = ['lock_ids', 'avoid_ids', 'max_hits', 'chip_windows'];
        $unknownKeys = array_diff(array_keys($decoded), $allowedKeys);
        if (!empty($unknownKeys)) {
            throw new InvalidArgumentException('Invalid constraints keys: ' . implode(', ', $unknownKeys));
        }

        $constraints = [];

        if (array_key_exists('lock_ids', $decoded)) {
            if (!is_array($decoded['lock_ids']) || !array_is_list($decoded['lock_ids'])) {
                throw new InvalidArgumentException('Invalid constraints.lock_ids: expected array of player IDs');
            }

            $constraints['lock_ids'] = array_map(static function ($id): int {
                if (!is_numeric($id) || (int) $id <= 0) {
                    throw new InvalidArgumentException('Invalid constraints.lock_ids: expected positive integers');
                }
                return (int) $id;
            }, $decoded['lock_ids']);
        }

        if (array_key_exists('avoid_ids', $decoded)) {
            if (!is_array($decoded['avoid_ids']) || !array_is_list($decoded['avoid_ids'])) {
                throw new InvalidArgumentException('Invalid constraints.avoid_ids: expected array of player IDs');
            }

            $constraints['avoid_ids'] = array_map(static function ($id): int {
                if (!is_numeric($id) || (int) $id <= 0) {
                    throw new InvalidArgumentException('Invalid constraints.avoid_ids: expected positive integers');
                }
                return (int) $id;
            }, $decoded['avoid_ids']);
        }

        if (array_key_exists('max_hits', $decoded)) {
            if (!is_numeric($decoded['max_hits']) || (int) $decoded['max_hits'] < 0) {
                throw new InvalidArgumentException('Invalid constraints.max_hits: expected non-negative integer');
            }
            $constraints['max_hits'] = (int) $decoded['max_hits'];
        }

        if (array_key_exists('chip_windows', $decoded)) {
            if (!is_array($decoded['chip_windows']) || array_is_list($decoded['chip_windows'])) {
                throw new InvalidArgumentException('Invalid constraints.chip_windows: expected object keyed by chip name');
            }

            $windows = [];
            foreach ($decoded['chip_windows'] as $chip => $window) {
                if (!is_string($chip) || !in_array($chip, $this->getValidChips(), true)) {
                    throw new InvalidArgumentException("Invalid constraints.chip_windows chip: {$chip}");
                }
                if (!is_array($window)) {
                    throw new InvalidArgumentException("Invalid constraints.chip_windows.{$chip}: expected object");
                }

                $normalizedWindow = [];
                if (array_key_exists('from', $window)) {
                    $normalizedWindow['from'] = $this->parsePlannerGameweekValue(
                        "constraints.chip_windows.{$chip}.from",
                        $window['from']
                    );
                }
                if (array_key_exists('to', $window)) {
                    $normalizedWindow['to'] = $this->parsePlannerGameweekValue(
                        "constraints.chip_windows.{$chip}.to",
                        $window['to']
                    );
                }
                if (
                    isset($normalizedWindow['from'], $normalizedWindow['to'])
                    && $normalizedWindow['from'] > $normalizedWindow['to']
                ) {
                    throw new InvalidArgumentException("Invalid constraints.chip_windows.{$chip}: from must be <= to");
                }

                $windows[$chip] = $normalizedWindow;
            }

            $constraints['chip_windows'] = $windows;
        }

        return $constraints;
    }
}
