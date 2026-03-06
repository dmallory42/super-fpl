<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use Maia\Orm\Connection;
use SuperFPL\Api\Models\Club;

class TeamService
{
    public function __construct(
        private readonly Connection $connection
    ) {
        Club::setConnection($this->connection);
    }

    /**
     * Get all teams/clubs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $teams = Club::query()
            ->select(
                'id',
                'name',
                'short_name',
                'strength_attack_home',
                'strength_attack_away',
                'strength_defence_home',
                'strength_defence_away'
            )
            ->orderBy('id')
            ->get();

        return array_map(
            static fn(Club $club): array => [
                'id' => $club->id,
                'name' => $club->name,
                'short_name' => $club->short_name,
                'strength_attack_home' => $club->strength_attack_home,
                'strength_attack_away' => $club->strength_attack_away,
                'strength_defence_home' => $club->strength_defence_home,
                'strength_defence_away' => $club->strength_defence_away,
            ],
            $teams
        );
    }
}
