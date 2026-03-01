<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\HasMany;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('managers')]
class Manager extends Model
{
    public int $id;
    public ?string $name = null;
    public ?string $team_name = null;
    public ?int $overall_rank = null;
    public ?int $overall_points = null;
    public ?string $last_synced = null;

    #[HasMany(ManagerPick::class, foreignKey: 'manager_id')]
    private array $picks;

    #[HasMany(ManagerHistory::class, foreignKey: 'manager_id')]
    private array $history;

    #[HasMany(LeagueMember::class, foreignKey: 'manager_id')]
    private array $leagueMemberships;
}
