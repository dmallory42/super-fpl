<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('player_goalscorer_odds')]
class GoalscorerOdds extends Model
{
    public ?int $player_id = null;
    public ?int $fixture_id = null;
    public ?float $anytime_scorer_prob = null;
    public ?int $line_count = null;
    public ?string $updated_at = null;

    #[BelongsTo(Player::class, foreignKey: 'player_id')]
    private Player $player;

    #[BelongsTo(Fixture::class, foreignKey: 'fixture_id')]
    private Fixture $fixture;
}
