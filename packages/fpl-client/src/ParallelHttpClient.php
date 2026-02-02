<?php

declare(strict_types=1);

namespace SuperFPL\FplClient;

/**
 * Parallel HTTP client using curl_multi for batch requests.
 * Use this for bulk operations where rate limiting isn't needed.
 */
class ParallelHttpClient
{
    private const USER_AGENT = 'SuperFPL-Client/1.0 (PHP)';
    private const BATCH_SIZE = 50; // Concurrent requests
    private const TIMEOUT = 30;

    public function __construct(
        private readonly string $baseUrl
    ) {
    }

    /**
     * Fetch multiple endpoints in parallel.
     *
     * @param string[] $endpoints Array of endpoint paths
     * @param int $delayBetweenBatchesMs Delay between batches in milliseconds
     * @return array<string, array|null> Map of endpoint => response data (null on failure)
     */
    public function getBatch(array $endpoints, int $delayBetweenBatchesMs = 100): array
    {
        $results = [];
        $batches = array_chunk($endpoints, self::BATCH_SIZE);

        foreach ($batches as $index => $batch) {
            // Small delay between batches (not the first one)
            if ($index > 0 && $delayBetweenBatchesMs > 0) {
                usleep($delayBetweenBatchesMs * 1000);
            }

            $batchResults = $this->fetchBatch($batch);
            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * @param string[] $endpoints
     * @return array<string, array|null>
     */
    private function fetchBatch(array $endpoints): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        // Create curl handles for each endpoint
        foreach ($endpoints as $endpoint) {
            $url = $this->baseUrl . $endpoint;
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ],
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$endpoint] = $ch;
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect results
        $results = [];
        foreach ($handles as $endpoint => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 200 && $response !== false) {
                $data = json_decode($response, true);
                $results[$endpoint] = is_array($data) ? $data : null;
            } else {
                $results[$endpoint] = null;
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }
}
