<?php

declare(strict_types=1);

namespace SuperFPL\Api\Sync;

use SuperFPL\Api\Database;
use SuperFPL\FplClient\FplClient;

class FixtureSync
{
    public function __construct(
        private readonly Database $db,
        private readonly FplClient $fplClient
    ) {}

    /**
     * Sync fixtures from FPL API.
     */
    public function sync(): int
    {
        $fixtures = $this->fplClient->fixtures()->getRaw();
        $count = 0;

        foreach ($fixtures as $fixture) {
            $this->db->upsert('fixtures', [
                'id' => $fixture['id'],
                'gameweek' => $fixture['event'],
                'home_club_id' => $fixture['team_h'],
                'away_club_id' => $fixture['team_a'],
                'kickoff_time' => $fixture['kickoff_time'],
                'home_score' => $fixture['team_h_score'],
                'away_score' => $fixture['team_a_score'],
                'home_difficulty' => $fixture['team_h_difficulty'],
                'away_difficulty' => $fixture['team_a_difficulty'],
                'finished' => $fixture['finished'] ? 1 : 0,
            ], ['id']);

            $count++;
        }

        return $count;
    }
}
