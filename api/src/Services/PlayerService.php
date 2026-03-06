<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use Maia\Orm\Connection;
use SuperFPL\Api\Models\Club;
use SuperFPL\Api\Models\Player;

class PlayerService
{
    public function __construct(
        private readonly Connection $connection
    ) {
        Player::setConnection($this->connection);
        Club::setConnection($this->connection);
    }

    /**
     * Get all players with optional filters.
     *
     * @param array{position?: int, team?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAll(array $filters = []): array
    {
        $query = Player::query()->select(
            'id',
            'code',
            'web_name',
            'first_name',
            'second_name',
            'club_id',
            'position',
            'now_cost',
            'total_points',
            'form',
            'selected_by_percent',
            'minutes',
            'goals_scored',
            'assists',
            'clean_sheets',
            'saves',
            'expected_goals',
            'expected_assists',
            'ict_index',
            'bps',
            'bonus',
            'starts',
            'appearances',
            'chance_of_playing',
            'news',
            'xmins_override',
            'penalty_order'
        );

        if (isset($filters['position'])) {
            $query->where('position', (int) $filters['position']);
        }

        if (isset($filters['team'])) {
            $query->where('club_id', (int) $filters['team']);
        }

        $players = $query->orderBy('total_points', 'desc')->get();

        return array_map(
            fn(Player $player): array => $this->mapPlayer($player),
            $players
        );
    }

    /**
     * Get a single player by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $player = Player::find($id);

        if ($player === null) {
            return null;
        }

        return $this->mapPlayer($player);
    }

    /**
     * @deprecated Use getAll() instead
     * @return array<int, array<string, mixed>>
     */
    public function getAllPlayers(): array
    {
        return $this->getAll();
    }

    /**
     * @deprecated Use getById() instead
     * @return array<string, mixed>|null
     */
    public function getPlayer(int $id): ?array
    {
        return $this->getById($id);
    }

    /**
     * @deprecated Use TeamService::getAll() instead
     * @return array<int, array<string, mixed>>
     */
    public function getAllTeams(): array
    {
        $teams = Club::query()
            ->select('id', 'name', 'short_name')
            ->orderBy('id')
            ->get();

        return array_map(
            static fn(Club $club): array => [
                'id' => $club->id,
                'name' => $club->name,
                'short_name' => $club->short_name,
            ],
            $teams
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPlayer(Player $player): array
    {
        return [
            'id' => $player->id,
            'code' => $player->code,
            'web_name' => $player->web_name,
            'first_name' => $player->first_name,
            'second_name' => $player->second_name,
            'team' => $player->club_id,
            'element_type' => $player->position,
            'now_cost' => $player->now_cost,
            'total_points' => $player->total_points,
            'form' => $player->form,
            'selected_by_percent' => $player->selected_by_percent,
            'minutes' => $player->minutes,
            'goals_scored' => $player->goals_scored,
            'assists' => $player->assists,
            'clean_sheets' => $player->clean_sheets,
            'saves' => $player->saves,
            'expected_goals' => $player->expected_goals,
            'expected_assists' => $player->expected_assists,
            'ict_index' => $player->ict_index,
            'bps' => $player->bps,
            'bonus' => $player->bonus,
            'starts' => $player->starts,
            'appearances' => $player->appearances,
            'chance_of_playing_next_round' => $player->chance_of_playing,
            'news' => $player->news,
            'xmins_override' => $player->xmins_override,
            'penalty_order' => $player->penalty_order,
        ];
    }
}
