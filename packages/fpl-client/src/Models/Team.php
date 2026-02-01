<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Models;

class Team
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $shortName,
        public readonly int $strength,
        public readonly int $strengthOverallHome,
        public readonly int $strengthOverallAway,
        public readonly int $strengthAttackHome,
        public readonly int $strengthAttackAway,
        public readonly int $strengthDefenceHome,
        public readonly int $strengthDefenceAway,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            shortName: (string) $data['short_name'],
            strength: (int) $data['strength'],
            strengthOverallHome: (int) $data['strength_overall_home'],
            strengthOverallAway: (int) $data['strength_overall_away'],
            strengthAttackHome: (int) $data['strength_attack_home'],
            strengthAttackAway: (int) $data['strength_attack_away'],
            strengthDefenceHome: (int) $data['strength_defence_home'],
            strengthDefenceAway: (int) $data['strength_defence_away'],
        );
    }
}
