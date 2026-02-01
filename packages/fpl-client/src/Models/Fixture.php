<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Models;

class Fixture
{
    public function __construct(
        public readonly int $id,
        public readonly int $event,
        public readonly int $teamHome,
        public readonly int $teamAway,
        public readonly ?int $teamHomeScore,
        public readonly ?int $teamAwayScore,
        public readonly bool $finished,
        public readonly bool $started,
        public readonly ?string $kickoffTime,
        public readonly int $teamHomeDifficulty,
        public readonly int $teamAwayDifficulty,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            event: (int) ($data['event'] ?? 0),
            teamHome: (int) $data['team_h'],
            teamAway: (int) $data['team_a'],
            teamHomeScore: isset($data['team_h_score']) ? (int) $data['team_h_score'] : null,
            teamAwayScore: isset($data['team_a_score']) ? (int) $data['team_a_score'] : null,
            finished: (bool) $data['finished'],
            started: (bool) $data['started'],
            kickoffTime: $data['kickoff_time'] ?? null,
            teamHomeDifficulty: (int) $data['team_h_difficulty'],
            teamAwayDifficulty: (int) $data['team_a_difficulty'],
        );
    }
}
