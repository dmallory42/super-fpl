<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use Maia\Orm\Connection;
use SuperFPL\Api\Models\Fixture;

class FixtureService
{
    public function __construct(
        private readonly Connection $connection
    ) {
        Fixture::setConnection($this->connection);
    }

    /**
     * Get all fixtures, optionally filtered by gameweek.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?int $gameweek = null): array
    {
        $query = Fixture::query()->select(
            'id',
            'gameweek',
            'home_club_id',
            'away_club_id',
            'kickoff_time',
            'home_score',
            'away_score',
            'home_difficulty',
            'away_difficulty',
            'finished'
        );

        if ($gameweek !== null) {
            $query->where('gameweek', $gameweek);
        }

        $fixtures = $query->orderBy('kickoff_time', 'asc')->get();

        return array_map(
            static fn(Fixture $fixture): array => [
                'id' => $fixture->id,
                'gameweek' => $fixture->gameweek,
                'home_club_id' => $fixture->home_club_id,
                'away_club_id' => $fixture->away_club_id,
                'kickoff_time' => $fixture->kickoff_time,
                'home_score' => $fixture->home_score,
                'away_score' => $fixture->away_score,
                'home_difficulty' => $fixture->home_difficulty,
                'away_difficulty' => $fixture->away_difficulty,
                'finished' => $fixture->finished,
            ],
            $fixtures
        );
    }
}
