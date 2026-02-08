<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Clients;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Clients\OddsApiClient;

class OddsApiClientTest extends TestCase
{
    /**
     * Test that oddsToProb correctly assigns home/away probabilities
     * by matching outcome names against event team names, regardless of
     * the order outcomes appear in the API response.
     *
     * This was the root cause of a bug where probabilities were swapped
     * (e.g. Sunderland 58% vs Liverpool 19%) because the code assumed
     * the first non-draw outcome was always the home team.
     */
    public function testOddsToProbMatchesTeamNamesCorrectly(): void
    {
        $client = new OddsApiClient('fake-key', '/tmp');
        $method = new \ReflectionMethod($client, 'oddsToProb');

        // Simulate API response where away team (Liverpool) appears first in outcomes
        // Liverpool has low odds (1.65 = strong favourite), Sunderland high odds (5.0 = underdog)
        $odds = [
            'Liverpool' => 1.65,
            'Sunderland' => 5.0,
            'Draw' => 4.0,
        ];

        $result = $method->invoke($client, $odds, 'Sunderland', 'Liverpool');

        // Sunderland is HOME — should be the underdog (~19%)
        // Liverpool is AWAY — should be the favourite (~56%)
        $this->assertLessThan(0.30, $result['home'], 'Home team (Sunderland) should be underdog');
        $this->assertGreaterThan(0.45, $result['away'], 'Away team (Liverpool) should be favourite');
        $this->assertGreaterThan(0.15, $result['draw']);

        // Probabilities should sum to 1.0
        $total = $result['home'] + $result['draw'] + $result['away'];
        $this->assertEqualsWithDelta(1.0, $total, 0.01);
    }

    public function testOddsToProbWhenHomeTeamAppearsFirst(): void
    {
        $client = new OddsApiClient('fake-key', '/tmp');
        $method = new \ReflectionMethod($client, 'oddsToProb');

        // Home team appears first — this was the "lucky" ordering before the fix
        $odds = [
            'Manchester City' => 1.35,
            'Fulham' => 7.5,
            'Draw' => 5.25,
        ];

        $result = $method->invoke($client, $odds, 'Manchester City', 'Fulham');

        // City (home) should be strong favourite
        $this->assertGreaterThan(0.60, $result['home'], 'Man City should be strong favourite');
        $this->assertLessThan(0.15, $result['away'], 'Fulham should be underdog');
    }

    public function testOddsToProbFallsBackOnUnrecognisedTeamNames(): void
    {
        $client = new OddsApiClient('fake-key', '/tmp');
        $method = new \ReflectionMethod($client, 'oddsToProb');

        // Outcome names don't match event team names at all
        $odds = [
            'Team A' => 2.0,
            'Team B' => 3.0,
            'Draw' => 3.5,
        ];

        $result = $method->invoke($client, $odds, 'Foo FC', 'Bar United');

        // Should fall back to iteration order
        $this->assertArrayHasKey('home', $result);
        $this->assertArrayHasKey('away', $result);
        $this->assertArrayHasKey('draw', $result);

        $total = $result['home'] + $result['draw'] + $result['away'];
        $this->assertEqualsWithDelta(1.0, $total, 0.01);
    }

    public function testOddsToProbNormalisesOverround(): void
    {
        $client = new OddsApiClient('fake-key', '/tmp');
        $method = new \ReflectionMethod($client, 'oddsToProb');

        // Typical odds with ~5% overround: 1/2.1 + 1/3.5 + 1/3.8 ≈ 1.05
        $odds = [
            'Arsenal' => 2.1,
            'Draw' => 3.5,
            'Brentford' => 3.8,
        ];

        $result = $method->invoke($client, $odds, 'Brentford', 'Arsenal');

        // After normalisation, should sum to exactly 1.0
        $total = $result['home'] + $result['draw'] + $result['away'];
        $this->assertEqualsWithDelta(1.0, $total, 0.01);

        // Brentford is home, Arsenal away — Arsenal should be favourite
        $this->assertGreaterThan($result['home'], $result['away']);
    }
}
