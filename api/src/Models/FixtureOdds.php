<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('fixture_odds')]
class FixtureOdds extends Model
{
    public ?int $fixture_id = null;
    public ?float $home_win_prob = null;
    public ?float $draw_prob = null;
    public ?float $away_win_prob = null;
    public ?float $home_cs_prob = null;
    public ?float $away_cs_prob = null;
    public ?float $expected_total_goals = null;
    public ?int $line_count = null;
    public ?string $updated_at = null;

    public static function primaryKey(): string
    {
        return 'fixture_id';
    }

    #[BelongsTo(Fixture::class, foreignKey: 'fixture_id')]
    private Fixture $fixture;
}
