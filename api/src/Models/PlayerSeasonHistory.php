<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('player_season_history')]
class PlayerSeasonHistory extends Model
{
    public ?int $player_code = null;
    public ?string $season_id = null;
    public ?int $total_points = null;
    public ?int $minutes = null;
    public ?int $goals_scored = null;
    public ?int $assists = null;
    public ?int $clean_sheets = null;
    public ?float $expected_goals = null;
    public ?float $expected_assists = null;
    public ?float $expected_goals_conceded = null;
    public ?int $starts = null;
    public ?int $start_cost = null;
    public ?int $end_cost = null;
}
