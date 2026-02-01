<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Models;

class Entry
{
    public function __construct(
        public readonly int $id,
        public readonly string $playerFirstName,
        public readonly string $playerLastName,
        public readonly string $name,
        public readonly int $summaryOverallPoints,
        public readonly int $summaryOverallRank,
        public readonly int $summaryEventPoints,
        public readonly int $summaryEventRank,
        public readonly ?int $currentEvent,
        public readonly int $startedEvent,
        public readonly int $lastDeadlineBank,
        public readonly int $lastDeadlineTotalTransfers,
        public readonly int $lastDeadlineValue,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            playerFirstName: (string) $data['player_first_name'],
            playerLastName: (string) $data['player_last_name'],
            name: (string) $data['name'],
            summaryOverallPoints: (int) ($data['summary_overall_points'] ?? 0),
            summaryOverallRank: (int) ($data['summary_overall_rank'] ?? 0),
            summaryEventPoints: (int) ($data['summary_event_points'] ?? 0),
            summaryEventRank: (int) ($data['summary_event_rank'] ?? 0),
            currentEvent: isset($data['current_event']) ? (int) $data['current_event'] : null,
            startedEvent: (int) $data['started_event'],
            lastDeadlineBank: (int) ($data['last_deadline_bank'] ?? 0),
            lastDeadlineTotalTransfers: (int) ($data['last_deadline_total_transfers'] ?? 0),
            lastDeadlineValue: (int) ($data['last_deadline_value'] ?? 0),
        );
    }
}
