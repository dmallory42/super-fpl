<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Services;

use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;
use SuperFPL\Api\Services\OwnershipService;
use SuperFPL\FplClient\Endpoints\EntryEndpoint;
use SuperFPL\FplClient\Endpoints\LeagueEndpoint;
use SuperFPL\FplClient\FplClient;

class OwnershipServiceTest extends TestCase
{
    private string $cacheDir;
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/superfpl-ownership-test-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0777, true);
        $this->db = new Database(':memory:');
        $this->db->init();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->cacheDir);
        parent::tearDown();
    }

    public function testGetCaptainPercentagesAggregatesOwnershipAndCaptaincy(): void
    {
        $leagueEndpoint = $this->createMock(LeagueEndpoint::class);
        $leagueEndpoint->method('standings')->willReturn([
            'standings' => [
                'results' => [
                    ['entry' => 1001],
                    ['entry' => 1002],
                ],
            ],
        ]);

        $entryOne = $this->createMock(EntryEndpoint::class);
        $entryOne->method('picks')->with(30)->willReturn([
            'picks' => [
                ['element' => 1, 'is_captain' => true, 'is_vice_captain' => false],
                ['element' => 2, 'is_captain' => false, 'is_vice_captain' => true],
            ],
        ]);
        $entryTwo = $this->createMock(EntryEndpoint::class);
        $entryTwo->method('picks')->with(30)->willReturn([
            'picks' => [
                ['element' => 1, 'is_captain' => true, 'is_vice_captain' => false],
                ['element' => 3, 'is_captain' => false, 'is_vice_captain' => false],
            ],
        ]);

        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('league')->willReturn($leagueEndpoint);
        $fplClient->method('entry')->willReturnCallback(
            static fn(int $entryId): EntryEndpoint => $entryId === 1001 ? $entryOne : $entryTwo
        );

        $service = new OwnershipService($this->db, $fplClient, $this->cacheDir);
        $result = $service->getCaptainPercentages(30, 2);

        $this->assertSame(2, (int) $result['sample_size']);
        $this->assertSame(100.0, (float) $result['captains'][1]['captain_percent']);
        $this->assertSame(100.0, (float) $result['ownership'][1]['owned_percent']);
        $this->assertSame(50.0, (float) $result['ownership'][2]['owned_percent']);
        $this->assertSame(50.0, (float) $result['ownership'][3]['owned_percent']);
    }

    public function testGetEffectiveOwnershipBuildsExpectedPercentages(): void
    {
        $leagueEndpoint = $this->createMock(LeagueEndpoint::class);
        $leagueEndpoint->method('standings')->willReturn([
            'standings' => [
                'results' => [
                    ['entry' => 2001],
                    ['entry' => 2002],
                ],
            ],
        ]);

        $entryOne = $this->createMock(EntryEndpoint::class);
        $entryOne->method('picks')->with(31)->willReturn([
            'picks' => [
                ['element' => 7, 'is_captain' => true, 'is_vice_captain' => false],
                ['element' => 8, 'is_captain' => false, 'is_vice_captain' => false],
            ],
        ]);
        $entryTwo = $this->createMock(EntryEndpoint::class);
        $entryTwo->method('picks')->with(31)->willReturn([
            'picks' => [
                ['element' => 7, 'is_captain' => false, 'is_vice_captain' => false],
                ['element' => 9, 'is_captain' => false, 'is_vice_captain' => false],
            ],
        ]);

        $fplClient = $this->createMock(FplClient::class);
        $fplClient->method('league')->willReturn($leagueEndpoint);
        $fplClient->method('entry')->willReturnCallback(
            static fn(int $entryId): EntryEndpoint => $entryId === 2001 ? $entryOne : $entryTwo
        );

        $service = new OwnershipService($this->db, $fplClient, $this->cacheDir);
        $result = $service->getEffectiveOwnership(31, 2);

        $eo = $result['effective_ownership'];
        $this->assertSame(2, (int) $result['sample_size']);
        $this->assertSame(150.0, (float) $eo[7]['effective_ownership']); // 100% owned + 50% captained
        $this->assertSame(50.0, (float) $eo[8]['effective_ownership']);
        $this->assertSame(50.0, (float) $eo[9]['effective_ownership']);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteTree($itemPath);
            } else {
                @unlink($itemPath);
            }
        }

        @rmdir($path);
    }
}
