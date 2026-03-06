<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('prediction_snapshots')]
class PredictionSnapshot extends Model
{
    public ?int $player_id = null;
    public ?int $gameweek = null;
    public ?float $predicted_points = null;
    public ?float $confidence = null;
    public ?string $breakdown = null;
    public ?string $model_version = null;
    public ?string $snapshot_source = null;
    public ?bool $is_pre_deadline = null;
    public ?string $snapped_at = null;

    #[BelongsTo(Player::class, foreignKey: 'player_id')]
    private Player $player;
}
