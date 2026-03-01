<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use Maia\Orm\Connection;

#[Controller]
class HealthController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route('/health', method: 'GET')]
    public function health(): Response
    {
        $dbStatus = 'ok';
        $message = null;

        try {
            $result = $this->connection->query('PRAGMA quick_check');
            if (($result[0]['quick_check'] ?? '') !== 'ok') {
                $dbStatus = 'error';
                $message = is_string($result[0]['quick_check'] ?? null)
                    ? $result[0]['quick_check']
                    : 'Unknown error';
            }
        } catch (\Throwable $exception) {
            $dbStatus = 'error';
            $message = $exception->getMessage();
        }

        $status = $dbStatus === 'ok' ? 'ok' : 'degraded';
        $httpCode = $status === 'ok' ? 200 : 503;
        $timestamp = date('c');

        return Response::json([
            'status' => $status,
            'timestamp' => $timestamp,
            'checks' => [
                'database' => [
                    'status' => $dbStatus,
                    'checked_at' => $timestamp,
                    'message' => $message,
                ],
            ],
        ], $httpCode);
    }
}
