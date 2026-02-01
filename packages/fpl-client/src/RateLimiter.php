<?php

declare(strict_types=1);

namespace SuperFPL\FplClient;

class RateLimiter
{
    private readonly string $lockFile;
    private readonly int $minInterval;

    public function __construct(
        string $lockDir = '/tmp',
        int $minIntervalMs = 1000
    ) {
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $this->lockFile = $lockDir . '/fpl_client_rate_limit.lock';
        $this->minInterval = $minIntervalMs;
    }

    public function wait(): void
    {
        $lastRequestTime = $this->getLastRequestTime();
        $now = $this->getCurrentTimeMs();
        $elapsed = $now - $lastRequestTime;

        if ($elapsed < $this->minInterval) {
            $sleepMs = $this->minInterval - $elapsed;
            usleep($sleepMs * 1000);
        }

        $this->setLastRequestTime($this->getCurrentTimeMs());
    }

    private function getLastRequestTime(): int
    {
        if (!file_exists($this->lockFile)) {
            return 0;
        }

        $content = file_get_contents($this->lockFile);
        if ($content === false) {
            return 0;
        }

        return (int) $content;
    }

    private function setLastRequestTime(int $timeMs): void
    {
        file_put_contents($this->lockFile, (string) $timeMs);
    }

    private function getCurrentTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
