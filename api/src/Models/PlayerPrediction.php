<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('player_predictions')]
class PlayerPrediction extends Model
{
    public ?int $player_id = null;
    public ?int $gameweek = null;
    public ?float $predicted_points = null;
    public ?float $predicted_if_fit = null;
    public ?float $expected_mins = null;
    public ?float $expected_mins_if_fit = null;
    public ?string $breakdown_json = null;
    public ?string $if_fit_breakdown_json = null;
    public ?float $confidence = null;
    public ?string $model_version = null;
    public ?string $computed_at = null;

    #[BelongsTo(Player::class, foreignKey: 'player_id')]
    private Player $player;
}
