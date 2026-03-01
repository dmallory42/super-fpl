<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\BelongsTo;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('fixtures')]
class Fixture extends Model
{
    public int $id;
    public ?int $gameweek = null;
    public ?int $home_club_id = null;
    public ?int $away_club_id = null;
    public ?string $kickoff_time = null;
    public ?int $home_score = null;
    public ?int $away_score = null;
    public ?int $home_difficulty = null;
    public ?int $away_difficulty = null;
    public ?bool $finished = null;

    #[BelongsTo(Club::class, foreignKey: 'home_club_id')]
    private Club $homeClub;

    #[BelongsTo(Club::class, foreignKey: 'away_club_id')]
    private Club $awayClub;
}
