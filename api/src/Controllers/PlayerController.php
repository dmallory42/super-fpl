<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\MiddlewareAttribute;
use Maia\Core\Routing\Route;
use Maia\Orm\Connection;
use SuperFPL\Api\Middleware\AdminAuthMiddleware;

#[Controller('/players')]
class PlayerController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route('', method: 'GET')]
    public function index(Request $request): Response
    {
        $filters = [];
        if ($request->query('position') !== null) {
            $filters['position'] = (int) $request->query('position');
        }
        if ($request->query('team') !== null) {
            $filters['team'] = (int) $request->query('team');
        }

        return Response::json([
            'players' => $this->getPlayers($filters),
            'teams' => $this->getTeams(),
        ]);
    }

    #[Route('/{id}', method: 'GET')]
    public function show(int $id): Response
    {
        $player = $this->connection->query(
            'SELECT
                id,
                code,
                web_name,
                first_name,
                second_name,
                club_id as team,
                position as element_type,
                now_cost,
                total_points,
                form,
                selected_by_percent,
                minutes,
                goals_scored,
                assists,
                clean_sheets,
                saves,
                expected_goals,
                expected_assists,
                ict_index,
                bps,
                bonus,
                starts,
                appearances,
                chance_of_playing as chance_of_playing_next_round,
                news,
                xmins_override,
                penalty_order
            FROM players
            WHERE id = ?',
            [$id]
        );

        if ($player === []) {
            return Response::json(['error' => 'Player not found'], 404);
        }

        return Response::json($player[0]);
    }

    #[Route('/{id}/xmins', method: 'PUT')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function setXMins(int $id, Request $request): Response
    {
        $body = $request->body();
        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON body'], 400);
        }

        $expectedMins = $body['expected_mins'] ?? null;
        if ($expectedMins === null || !is_numeric($expectedMins)) {
            return Response::json(['error' => 'Missing or invalid expected_mins'], 400);
        }

        $expectedMins = max(0, min(95, (int) $expectedMins));
        $this->connection->execute('UPDATE players SET xmins_override = ? WHERE id = ?', [$expectedMins, $id]);

        return Response::json([
            'success' => true,
            'player_id' => $id,
            'expected_mins' => $expectedMins,
        ]);
    }

    /**
     * @param array{position?: int, team?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function getPlayers(array $filters): array
    {
        $sql = 'SELECT
            id,
            code,
            web_name,
            first_name,
            second_name,
            club_id as team,
            position as element_type,
            now_cost,
            total_points,
            form,
            selected_by_percent,
            minutes,
            goals_scored,
            assists,
            clean_sheets,
            saves,
            expected_goals,
            expected_assists,
            ict_index,
            bps,
            bonus,
            starts,
            appearances,
            chance_of_playing as chance_of_playing_next_round,
            news,
            xmins_override,
            penalty_order
        FROM players';

        $conditions = [];
        $params = [];

        if (isset($filters['position'])) {
            $conditions[] = 'position = ?';
            $params[] = $filters['position'];
        }

        if (isset($filters['team'])) {
            $conditions[] = 'club_id = ?';
            $params[] = $filters['team'];
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY total_points DESC';

        return $this->connection->query($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTeams(): array
    {
        return $this->connection->query(
            'SELECT
                id,
                name,
                short_name,
                strength_attack_home,
                strength_attack_away,
                strength_defence_home,
                strength_defence_away
            FROM clubs
            ORDER BY id'
        );
    }
}
