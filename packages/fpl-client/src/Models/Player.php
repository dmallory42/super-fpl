<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Models;

class Player
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $secondName,
        public readonly string $webName,
        public readonly int $team,
        public readonly int $elementType,
        public readonly int $nowCost,
        public readonly int $totalPoints,
        public readonly float $selectedByPercent,
        public readonly int $minutes,
        public readonly int $goalsScored,
        public readonly int $assists,
        public readonly int $cleanSheets,
        public readonly string $status,
        public readonly ?int $chanceOfPlayingNextRound,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            firstName: (string) $data['first_name'],
            secondName: (string) $data['second_name'],
            webName: (string) $data['web_name'],
            team: (int) $data['team'],
            elementType: (int) $data['element_type'],
            nowCost: (int) $data['now_cost'],
            totalPoints: (int) $data['total_points'],
            selectedByPercent: (float) $data['selected_by_percent'],
            minutes: (int) $data['minutes'],
            goalsScored: (int) $data['goals_scored'],
            assists: (int) $data['assists'],
            cleanSheets: (int) $data['clean_sheets'],
            status: (string) $data['status'],
            chanceOfPlayingNextRound: isset($data['chance_of_playing_next_round'])
                ? (int) $data['chance_of_playing_next_round']
                : null,
        );
    }
}
