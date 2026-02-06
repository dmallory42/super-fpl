<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Prediction;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Prediction\CardProbability;

class CardProbabilityTest extends TestCase
{
    private CardProbability $prob;

    protected function setUp(): void
    {
        $this->prob = new CardProbability();
    }

    public function testYellowCardRate(): void
    {
        $player = [
            'minutes' => 1800,
            'yellow_cards' => 6,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->prob->calculate($player);

        // 6 yellows in 1800 mins = 0.3 per 90 → -0.3 expected pts
        $this->assertLessThan(0, $result);
        $this->assertEqualsWithDelta(-0.3, $result, 0.01);
    }

    public function testRedCardRate(): void
    {
        $player = [
            'minutes' => 1800,
            'yellow_cards' => 0,
            'red_cards' => 1,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->prob->calculate($player);

        // 1 red in 1800 mins = 0.05 per 90 → -0.15 expected pts
        $this->assertLessThan(0, $result);
        $this->assertEqualsWithDelta(-0.15, $result, 0.01);
    }

    public function testCombinedDeductions(): void
    {
        $player = [
            'minutes' => 1800,
            'yellow_cards' => 4,
            'red_cards' => 1,
            'own_goals' => 1,
            'penalties_missed' => 1,
        ];

        $result = $this->prob->calculate($player);

        // yellow: (4/1800)*90 * -1 = -0.2
        // red: (1/1800)*90 * -3 = -0.15
        // own goal: (1/1800)*90 * -2 = -0.1
        // pen miss: (1/1800)*90 * -2 = -0.1
        // total: -0.55
        $this->assertLessThan(0, $result);
        $this->assertEqualsWithDelta(-0.55, $result, 0.01);
    }

    public function testZeroMinutesReturnsZero(): void
    {
        $player = [
            'minutes' => 0,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->prob->calculate($player);

        $this->assertEquals(0.0, $result);
    }

    public function testCleanRecord(): void
    {
        $player = [
            'minutes' => 1800,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'own_goals' => 0,
            'penalties_missed' => 0,
        ];

        $result = $this->prob->calculate($player);

        $this->assertEquals(0.0, $result);
    }
}
