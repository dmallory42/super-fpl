<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('players')]
class Player extends Model
{
    public int $id;
    public ?int $code = null;
    public ?string $web_name = null;
    public ?string $first_name = null;
    public ?string $second_name = null;
    public ?int $club_id = null;
    public ?int $position = null;
    public ?int $now_cost = null;
    public ?int $total_points = null;
    public ?float $form = null;
    public ?float $selected_by_percent = null;
    public ?int $minutes = null;
    public ?int $goals_scored = null;
    public ?int $assists = null;
    public ?int $clean_sheets = null;
    public ?float $expected_goals = null;
    public ?float $expected_assists = null;
    public ?float $expected_goals_conceded = null;
    public ?float $ict_index = null;
    public ?int $bps = null;
    public ?int $bonus = null;
    public ?int $starts = null;
    public ?int $chance_of_playing = null;
    public ?string $news = null;
    public ?int $penalties_order = null;
    public ?int $penalties_taken = null;
    public ?int $defensive_contribution = null;
    public ?float $defensive_contribution_per_90 = null;
    public ?int $saves = null;
    public ?int $appearances = null;
    public ?int $yellow_cards = null;
    public ?int $red_cards = null;
    public ?int $own_goals = null;
    public ?int $penalties_missed = null;
    public ?int $penalties_saved = null;
    public ?int $goals_conceded = null;
    public ?string $updated_at = null;
    public ?int $understat_id = null;
    public ?float $npxg = null;
    public ?int $npg = null;
    public ?int $understat_shots = null;
    public ?int $understat_key_passes = null;
    public ?float $xg_chain = null;
    public ?float $xg_buildup = null;
    public ?float $understat_xa = null;
    public ?int $xmins_override = null;
    public ?int $penalty_order = null;

    #[BelongsTo(Club::class, foreignKey: 'club_id')]
    private Club $club;
}
