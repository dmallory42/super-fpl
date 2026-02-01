<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Endpoints;

use SuperFPL\FplClient\HttpClient;
use SuperFPL\FplClient\Models\Entry;

class EntryEndpoint
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $entryId
    ) {
    }

    public function get(): Entry
    {
        $data = $this->httpClient->get("entry/{$this->entryId}/");
        return Entry::fromArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->httpClient->get("entry/{$this->entryId}/");
    }

    /**
     * @return array<string, mixed>
     */
    public function history(): array
    {
        return $this->httpClient->get("entry/{$this->entryId}/history/");
    }

    /**
     * @return array<string, mixed>
     */
    public function picks(int $gameweek): array
    {
        return $this->httpClient->get("entry/{$this->entryId}/event/{$gameweek}/picks/");
    }

    /**
     * @return array<string, mixed>
     */
    public function transfers(): array
    {
        return $this->httpClient->get("entry/{$this->entryId}/transfers/");
    }
}
