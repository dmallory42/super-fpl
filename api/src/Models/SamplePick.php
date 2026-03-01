<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('sample_picks')]
class SamplePick extends Model
{
    public int $id;
    public ?int $gameweek = null;
    public ?string $tier = null;
    public ?int $manager_id = null;
    public ?int $player_id = null;
    public ?int $multiplier = null;
    public ?string $created_at = null;

    #[BelongsTo(Manager::class, foreignKey: 'manager_id')]
    private Manager $manager;

    #[BelongsTo(Player::class, foreignKey: 'player_id')]
    private Player $player;
}
