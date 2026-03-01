<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('manager_history')]
class ManagerHistory extends Model
{
    public ?int $manager_id = null;
    public ?int $gameweek = null;
    public ?int $points = null;
    public ?int $total_points = null;
    public ?int $overall_rank = null;
    public ?int $bank = null;
    public ?int $team_value = null;
    public ?int $transfers_cost = null;
    public ?int $points_on_bench = null;

    #[BelongsTo(Manager::class, foreignKey: 'manager_id')]
    private Manager $manager;
}
