<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('player_gameweek_history')]
class GameweekHistory extends Model
{
    public ?int $player_id = null;
    public ?int $gameweek = null;
    public ?int $fixture_id = null;
    public ?int $opponent_team = null;
    public ?bool $was_home = null;
    public ?int $minutes = null;
    public ?int $goals_scored = null;
    public ?int $assists = null;
    public ?int $clean_sheets = null;
    public ?int $goals_conceded = null;
    public ?int $bonus = null;
    public ?int $bps = null;
    public ?int $total_points = null;
    public ?float $expected_goals = null;
    public ?float $expected_assists = null;
    public ?float $expected_goals_conceded = null;
    public ?int $value = null;
    public ?int $selected = null;

    #[BelongsTo(Player::class, foreignKey: 'player_id')]
    private Player $player;

    #[BelongsTo(Fixture::class, foreignKey: 'fixture_id')]
    private Fixture $fixture;
}
