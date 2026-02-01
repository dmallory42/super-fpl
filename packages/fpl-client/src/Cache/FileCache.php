<?php

declare(strict_types=1);

namespace SuperFPL\FplClient\Cache;

class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $cacheDir
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->getPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            return null;
        }

        if ($data['expires'] < time()) {
            unlink($path);
            return null;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $path = $this->getPath($key);
        $data = [
            'expires' => time() + $ttl,
            'value' => $value,
        ];

        $result = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        return $result !== false;
    }

    public function has(string $key): bool
    {
        $path = $this->getPath($key);

        if (!file_exists($path)) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'])) {
            return false;
        }

        if ($data['expires'] < time()) {
            unlink($path);
            return false;
        }

        return true;
    }

    private function getPath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.json';
    }
}
