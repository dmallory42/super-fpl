<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Endpoints;

use SuperFPL\FplClient\HttpClient;
use SuperFPL\FplClient\Models\Gameweek;
use SuperFPL\FplClient\Models\Player;
use SuperFPL\FplClient\Models\Team;

class BootstrapEndpoint
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        private readonly HttpClient $httpClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->getData();
    }

    /**
     * @return Player[]
     */
    public function getPlayers(): array
    {
        $data = $this->getData();
        return array_map(
            fn(array $element) => Player::fromArray($element),
            $data['elements'] ?? []
        );
    }

    /**
     * @return Team[]
     */
    public function getTeams(): array
    {
        $data = $this->getData();
        return array_map(
            fn(array $team) => Team::fromArray($team),
            $data['teams'] ?? []
        );
    }

    /**
     * @return Gameweek[]
     */
    public function getGameweeks(): array
    {
        $data = $this->getData();
        return array_map(
            fn(array $event) => Gameweek::fromArray($event),
            $data['events'] ?? []
        );
    }

    /**
     * @return array<int, array{id: int, singular_name: string, singular_name_short: string, plural_name: string, plural_name_short: string}>
     */
    public function getElementTypes(): array
    {
        $data = $this->getData();
        return $data['element_types'] ?? [];
    }

    public function getCurrentGameweek(): ?Gameweek
    {
        $gameweeks = $this->getGameweeks();
        foreach ($gameweeks as $gameweek) {
            if ($gameweek->isCurrent) {
                return $gameweek;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        if ($this->data === null) {
            $this->data = $this->httpClient->get('bootstrap-static/');
        }
        return $this->data;
    }
}
