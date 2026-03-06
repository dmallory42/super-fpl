<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\HasMany;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('clubs')]
class Club extends Model
{
    public int $id;
    public ?string $name = null;
    public ?string $short_name = null;
    public ?int $strength_attack_home = null;
    public ?int $strength_attack_away = null;
    public ?int $strength_defence_home = null;
    public ?int $strength_defence_away = null;

    #[HasMany(Player::class, foreignKey: 'club_id')]
    private array $players;

    #[HasMany(Fixture::class, foreignKey: 'home_club_id')]
    private array $homeFixtures;

    #[HasMany(Fixture::class, foreignKey: 'away_club_id')]
    private array $awayFixtures;
}
