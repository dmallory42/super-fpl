<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Support;

use RuntimeException;
use SuperFPL\FplClient\Endpoints\BootstrapEndpoint;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\Endpoints\FixturesEndpoint;
use SuperFPL\FplClient\Endpoints\LeagueEndpoint;
use SuperFPL\FplClient\Endpoints\LiveEndpoint;
use SuperFPL\FplClient\FplClient;
use SuperFPL\FplClient\Models\Entry;

/**
 * Test double used by controller tests to avoid external API calls.
 */
class FakeFplClient extends FplClient
{
    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<int, array<string, mixed>> $leagues
     * @param array<int, array<string, mixed>> $liveData
     * @param array<int, array<string, mixed>> $fixturesData
     * @param array<string, mixed> $bootstrapData
     */
    public function __construct(
        private readonly array $entries = [],
        private readonly array $leagues = [],
        private readonly array $liveData = [],
        private readonly array $fixturesData = [],
        private readonly array $bootstrapData = []
    ) {
    }

    public function entry(int $id): EntryEndpoint
    {
        return new FakeEntryEndpoint($this->entries[$id] ?? null, $id);
    }

    public function league(int $id): LeagueEndpoint
    {
        return new FakeLeagueEndpoint($this->leagues[$id] ?? null, $id);
    }

    public function live(int $gameweek): LiveEndpoint
    {
        return new FakeLiveEndpoint($this->liveData[$gameweek] ?? null, $gameweek);
    }

    public function fixtures(): FixturesEndpoint
    {
        return new FakeFixturesEndpoint($this->fixturesData);
    }

    public function bootstrap(): BootstrapEndpoint
    {
        return new FakeBootstrapEndpoint($this->bootstrapData);
    }
}

class FakeEntryEndpoint extends EntryEndpoint
{
    /**
     * @param array<string, mixed>|null $entryData
     */
    public function __construct(
        private readonly ?array $entryData,
        private readonly int $entryId
    ) {
    }

    public function get(): Entry
    {
        return Entry::fromArray($this->getRaw());
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        if ($this->entryData === null || ($this->entryData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake entry {$this->entryId} not found");
        }

        return is_array($this->entryData['raw'] ?? null) ? $this->entryData['raw'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(): array
    {
        if ($this->entryData === null || ($this->entryData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake entry history {$this->entryId} not found");
        }

        return is_array($this->entryData['history'] ?? null) ? $this->entryData['history'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function picks(int $gameweek): array
    {
        if ($this->entryData === null || ($this->entryData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake entry picks {$this->entryId} GW{$gameweek} not found");
        }

        $picksByGw = $this->entryData['picks'] ?? [];
        if (!is_array($picksByGw)) {
            return [];
        }

        $value = $picksByGw[$gameweek] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function transfers(): array
    {
        if ($this->entryData === null || ($this->entryData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake entry transfers {$this->entryId} not found");
        }

        $value = $this->entryData['transfers'] ?? [];

        return is_array($value) ? $value : [];
    }
}

class FakeLeagueEndpoint extends LeagueEndpoint
{
    /**
     * @param array<string, mixed>|null $leagueData
     */
    public function __construct(
        private readonly ?array $leagueData,
        private readonly int $leagueId
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function standings(int $page = 1): array
    {
        if ($this->leagueData === null || ($this->leagueData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake league {$this->leagueId} not found");
        }

        $pages = $this->leagueData['standings'] ?? [];
        if (!is_array($pages)) {
            return [];
        }

        $value = $pages[$page] ?? ($pages[1] ?? []);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllResults(): array
    {
        if ($this->leagueData === null || ($this->leagueData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake league {$this->leagueId} not found");
        }

        $results = $this->leagueData['all_results'] ?? [];

        return is_array($results) ? $results : [];
    }
}

class FakeLiveEndpoint extends LiveEndpoint
{
    /**
     * @param array<string, mixed>|null $liveData
     */
    public function __construct(
        private readonly ?array $liveData,
        private readonly int $gameweek
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if ($this->liveData === null || ($this->liveData['throws'] ?? false) === true) {
            throw new RuntimeException("Fake live data GW{$this->gameweek} not found");
        }

        return $this->liveData;
    }
}

class FakeFixturesEndpoint extends FixturesEndpoint
{
    /**
     * @param array<int, array<string, mixed>> $fixturesData
     */
    public function __construct(
        private readonly array $fixturesData
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function getRaw(?int $gameweek = null, bool $useCache = true): array
    {
        if ($gameweek === null) {
            return array_values($this->fixturesData);
        }

        return array_values(array_filter(
            $this->fixturesData,
            static fn(array $fixture): bool => (int) ($fixture['event'] ?? 0) === $gameweek
        ));
    }
}

class FakeBootstrapEndpoint extends BootstrapEndpoint
{
    /**
     * @param array<string, mixed> $bootstrapData
     */
    public function __construct(
        private readonly array $bootstrapData
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->bootstrapData;
    }
}
