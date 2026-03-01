<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('league_members')]
class LeagueMember extends Model
{
    public ?int $league_id = null;
    public ?int $manager_id = null;
    public ?int $rank = null;

    #[BelongsTo(League::class, foreignKey: 'league_id')]
    private League $league;

    #[BelongsTo(Manager::class, foreignKey: 'manager_id')]
    private Manager $manager;
}
