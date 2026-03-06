<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('manager_picks')]
class ManagerPick extends Model
{
    public ?int $manager_id = null;
    public ?int $gameweek = null;
    public ?int $player_id = null;
    public ?int $position = null;
    public ?int $multiplier = null;
    public ?bool $is_captain = null;
    public ?bool $is_vice_captain = null;

    #[BelongsTo(Manager::class, foreignKey: 'manager_id')]
    private Manager $manager;

    #[BelongsTo(Player::class, foreignKey: 'player_id')]
    private Player $player;
}
