<?php

declare(strict_types=1);

namespace SuperFPL\FplClient;

class RateLimiter
{
    private readonly string $lockFile;
    private readonly int $minInterval;
    private bool $usesFileLock = false;
    private static int $fallbackLastRequestTimeMs = 0;

    public function __construct(
        string $lockDir = '/tmp',
        int $minIntervalMs = 1000
    ) {
        $normalizedLockDir = rtrim($lockDir, '/');
        if ($normalizedLockDir === '') {
            $normalizedLockDir = '/tmp';
        }

        if (!is_dir($normalizedLockDir)) {
            @mkdir($normalizedLockDir, 0777, true);
        }

        // Keep lock dir/file writable across php-fpm (www-data) and cron/root processes.
        @chmod($normalizedLockDir, 0777);

        $this->lockFile = $normalizedLockDir . '/fpl_client_rate_limit.lock';
        $this->minInterval = $minIntervalMs;

        if (!file_exists($this->lockFile)) {
            if (@touch($this->lockFile)) {
                @chmod($this->lockFile, 0666);
            }
        } elseif (!is_writable($this->lockFile)) {
            @chmod($this->lockFile, 0666);
        }

        $this->usesFileLock = is_file($this->lockFile)
            && is_readable($this->lockFile)
            && is_writable($this->lockFile);
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

        $updatedAt = $this->getCurrentTimeMs();
        $this->setLastRequestTime($updatedAt);
    }

    private function getLastRequestTime(): int
    {
        if (!$this->usesFileLock) {
            return self::$fallbackLastRequestTimeMs;
        }

        if (!file_exists($this->lockFile)) {
            return 0;
        }

        $content = @file_get_contents($this->lockFile);
        if ($content === false) {
            return 0;
        }

        return (int) $content;
    }

    private function setLastRequestTime(int $timeMs): void
    {
        if (!$this->usesFileLock) {
            self::$fallbackLastRequestTimeMs = $timeMs;
            return;
        }

        $result = @file_put_contents($this->lockFile, (string) $timeMs, LOCK_EX);
        if ($result === false) {
            $this->usesFileLock = false;
            self::$fallbackLastRequestTimeMs = $timeMs;
            return;
        }

        @chmod($this->lockFile, 0666);
    }

    private function getCurrentTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
