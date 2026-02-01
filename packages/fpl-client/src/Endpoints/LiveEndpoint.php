<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Endpoints;

use SuperFPL\FplClient\HttpClient;

class LiveEndpoint
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $gameweek
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->httpClient->get("event/{$this->gameweek}/live/", useCache: false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getElements(): array
    {
        $data = $this->get();
        return $data['elements'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getElement(int $elementId): ?array
    {
        $elements = $this->getElements();
        foreach ($elements as $element) {
            if (isset($element['id']) && (int) $element['id'] === $elementId) {
                return $element;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getElementStats(int $elementId): ?array
    {
        $element = $this->getElement($elementId);
        return $element['stats'] ?? null;
    }
}
