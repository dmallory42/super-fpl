<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\HasMany;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table('leagues')]
class League extends Model
{
    public int $id;
    public ?string $name = null;
    public ?string $type = null;
    public ?string $last_synced = null;

    #[HasMany(LeagueMember::class, foreignKey: 'league_id')]
    private array $members;
}
