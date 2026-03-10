<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use Maia\Orm\Connection;

#[Controller('/fixtures')]
class FixtureController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route('', method: 'GET')]
    public function get_fixtures(Request $request): Response
    {
        $gameweek = $request->query('gameweek');

        $sql = 'SELECT
            id,
            gameweek,
            home_club_id,
            away_club_id,
            kickoff_time,
            home_score,
            away_score,
            home_difficulty,
            away_difficulty,
            finished
        FROM fixtures';
        $params = [];

        if ($gameweek !== null) {
            $sql .= ' WHERE gameweek = ?';
            $params[] = (int) $gameweek;
        }

        $sql .= ' ORDER BY kickoff_time ASC';

        return Response::json([
            'fixtures' => $this->connection->query($sql, $params),
        ]);
    }

    #[Route('/status', method: 'GET')]
    public function get_fixture_status(): Response
    {
        $fixtures = $this->connection->query(
            'SELECT
                id,
                gameweek,
                kickoff_time,
                finished,
                home_score,
                away_score,
                home_club_id,
                away_club_id
            FROM fixtures
            WHERE kickoff_time IS NOT NULL
            ORDER BY kickoff_time ASC'
        );

        $now = time();
        foreach ($fixtures as &$fixture) {
            $kickoff = strtotime((string) $fixture['kickoff_time']);
            if ($kickoff === false) {
                $fixture['started'] = false;
                $fixture['finished'] = (bool) ($fixture['finished'] ?? false);
                $fixture['minutes'] = 0;
                continue;
            }

            $fixture['started'] = $now >= $kickoff;
            $minutesSinceKickoff = ($now - $kickoff) / 60;

            if (!(bool) ($fixture['finished'] ?? false) && $minutesSinceKickoff >= 120) {
                $fixture['finished'] = true;
            } else {
                $fixture['finished'] = (bool) ($fixture['finished'] ?? false);
            }

            $fixture['minutes'] = $fixture['started'] && !$fixture['finished']
                ? min(90, (int) $minutesSinceKickoff)
                : ($fixture['finished'] ? 90 : 0);
        }
        unset($fixture);

        $byGameweek = [];
        foreach ($fixtures as $fixture) {
            $gameweek = (int) ($fixture['gameweek'] ?? 0);
            if (!isset($byGameweek[$gameweek])) {
                $byGameweek[$gameweek] = [
                    'gameweek' => $gameweek,
                    'fixtures' => [],
                    'total' => 0,
                    'started' => 0,
                    'finished' => 0,
                    'first_kickoff' => null,
                    'last_kickoff' => null,
                ];
            }

            $byGameweek[$gameweek]['fixtures'][] = $fixture;
            $byGameweek[$gameweek]['total']++;

            if (($fixture['started'] ?? false) === true) {
                $byGameweek[$gameweek]['started']++;
            }
            if (($fixture['finished'] ?? false) === true) {
                $byGameweek[$gameweek]['finished']++;
            }

            $kickoffTime = $fixture['kickoff_time'] ?? null;
            if (
                is_string($kickoffTime)
                && ($byGameweek[$gameweek]['first_kickoff'] === null || $kickoffTime < $byGameweek[$gameweek]['first_kickoff'])
            ) {
                $byGameweek[$gameweek]['first_kickoff'] = $kickoffTime;
            }
            if (
                is_string($kickoffTime)
                && ($byGameweek[$gameweek]['last_kickoff'] === null || $kickoffTime > $byGameweek[$gameweek]['last_kickoff'])
            ) {
                $byGameweek[$gameweek]['last_kickoff'] = $kickoffTime;
            }
        }

        $activeGameweek = null;
        $latestFinished = null;

        foreach ($byGameweek as $gameweek => $data) {
            $firstKickoff = strtotime((string) ($data['first_kickoff'] ?? ''));
            $lastKickoff = strtotime((string) ($data['last_kickoff'] ?? ''));

            if ($firstKickoff === false || $lastKickoff === false) {
                if ($data['finished'] === $data['total'] && $data['total'] > 0) {
                    $latestFinished = $gameweek;
                }
                continue;
            }

            $gameweekStart = $firstKickoff - (90 * 60);
            $gameweekEnd = $lastKickoff + (12 * 60 * 60);

            if ($now >= $gameweekStart && $now <= $gameweekEnd) {
                $activeGameweek = $gameweek;
                break;
            }

            if ($data['finished'] === $data['total'] && $data['total'] > 0) {
                $latestFinished = $gameweek;
            }
        }

        return Response::json([
            'current_gameweek' => $activeGameweek ?? $latestFinished ?? 1,
            'is_live' => $activeGameweek !== null,
            'gameweeks' => array_values($byGameweek),
        ]);
    }
}
