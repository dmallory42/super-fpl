<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Models;

class Gameweek
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $deadlineTime,
        public readonly bool $finished,
        public readonly bool $isCurrent,
        public readonly bool $isNext,
        public readonly bool $isPrevious,
        public readonly ?int $highestScore,
        public readonly ?int $averageEntryScore,
        public readonly int $mostSelected,
        public readonly int $mostCaptained,
        public readonly int $mostViceCaptained,
        public readonly int $mostTransferredIn,
        public readonly int $topElementPoints,
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
            deadlineTime: (string) $data['deadline_time'],
            finished: (bool) $data['finished'],
            isCurrent: (bool) $data['is_current'],
            isNext: (bool) $data['is_next'],
            isPrevious: (bool) $data['is_previous'],
            highestScore: $data['highest_score'] ?? null,
            averageEntryScore: $data['average_entry_score'] ?? null,
            mostSelected: (int) ($data['most_selected'] ?? 0),
            mostCaptained: (int) ($data['most_captained'] ?? 0),
            mostViceCaptained: (int) ($data['most_vice_captained'] ?? 0),
            mostTransferredIn: (int) ($data['most_transferred_in'] ?? 0),
            topElementPoints: (int) ($data['top_element_info']['points'] ?? 0),
        );
    }
}
