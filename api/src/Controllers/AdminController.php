<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Config\Config;
use Maia\Core\Http\Request;
use Maia\Core\Http\Response;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\MiddlewareAttribute;
use Maia\Core\Routing\Route;
use Maia\Orm\Connection;
use SuperFPL\Api\Clients\OddsApiClient;
use SuperFPL\Api\Clients\UnderstatClient;
use SuperFPL\Api\Middleware\AdminAuthMiddleware;
use SuperFPL\Api\Services\SampleService;
use SuperFPL\Api\Sync\FixtureSync;
use SuperFPL\Api\Sync\ManagerSync;
use SuperFPL\Api\Sync\OddsSync;
use SuperFPL\Api\Sync\PlayerSync;
use SuperFPL\Api\Sync\UnderstatSync;
use SuperFPL\FplClient\FplClient;

#[Controller]
class AdminController extends LegacyController
{
    public function __construct(
        Connection $connection,
        Config $config,
        private readonly FplClient $fplClient
    ) {
        parent::__construct($connection, $config);
    }

    #[Route('/admin/login', method: 'POST')]
    public function login(Request $request): Response
    {
        $expectedToken = $this->expectedAdminToken();
        if ($expectedToken === '') {
            return Response::json(['error' => 'Admin auth not configured'], 503);
        }

        $body = $request->body();
        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON body'], 400);
        }

        $providedToken = trim((string) ($body['token'] ?? ''));
        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $sessionHash = hash('sha256', $expectedToken);
        $xsrfToken = bin2hex(random_bytes(16));

        $this->setCookie('superfpl_admin', $sessionHash, true, 43200);
        $this->setCookie('XSRF-TOKEN', $xsrfToken, false, 43200);

        return Response::json(['success' => true]);
    }

    #[Route('/admin/session', method: 'GET')]
    public function session(Request $request): Response
    {
        $expectedToken = $this->expectedAdminToken();
        if ($expectedToken === '') {
            return Response::json(['error' => 'Admin auth not configured'], 503);
        }

        $cookies = $this->parseCookieHeader((string) $request->header('cookie', ''));
        $session = trim((string) ($cookies['superfpl_admin'] ?? ''));
        if ($session === '' || !hash_equals(hash('sha256', $expectedToken), $session)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return Response::json(['authenticated' => true]);
    }

    #[Route('/sync/players', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncPlayers(): Response
    {
        $sync = new PlayerSync($this->connection, $this->fplClient);
        $result = $sync->sync();

        return Response::json([
            'success' => true,
            'players_synced' => $result['players'],
            'teams_synced' => $result['teams'],
        ]);
    }

    #[Route('/sync/players', method: 'POST')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncPlayersPost(): Response
    {
        return $this->syncPlayers();
    }

    #[Route('/sync/fixtures', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncFixtures(): Response
    {
        $sync = new FixtureSync($this->connection, $this->fplClient);
        $count = $sync->sync();

        return Response::json([
            'success' => true,
            'fixtures_synced' => $count,
        ]);
    }

    #[Route('/sync/fixtures', method: 'POST')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncFixturesPost(): Response
    {
        return $this->syncFixtures();
    }

    #[Route('/sync/managers', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncManagers(): Response
    {
        $sync = new ManagerSync($this->connection, $this->fplClient);
        $result = $sync->syncAll();

        return Response::json([
            'success' => true,
            'synced' => $result['synced'],
            'failed' => $result['failed'],
        ]);
    }

    #[Route('/sync/managers', method: 'POST')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncManagersPost(): Response
    {
        return $this->syncManagers();
    }

    #[Route('/sync/odds', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncOdds(): Response
    {
        $apiKey = (string) $this->config->get('config.odds_api.api_key', '');
        if ($apiKey === '') {
            return Response::json([
                'error' => 'ODDS_API_KEY not configured',
                'note' => 'Set ODDS_API_KEY env var for odds data',
            ], 500);
        }

        $cachePath = $this->cachePath('/odds');
        $client = new OddsApiClient($apiKey, $cachePath);
        $sync = new OddsSync($this->connection, $client);

        $match = $sync->syncMatchOdds();
        $goalscorer = $sync->syncAllGoalscorerOdds();
        $assist = $sync->syncAllAssistOdds();

        return Response::json([
            'success' => true,
            'match_odds' => [
                'fixtures_found' => $match['fixtures'],
                'fixtures_matched' => $match['matched'],
            ],
            'goalscorer_odds' => [
                'fixtures_matched' => $goalscorer['fixtures'],
                'players_synced' => $goalscorer['players'],
            ],
            'assist_odds' => [
                'fixtures_matched' => $assist['fixtures'],
                'players_synced' => $assist['players'],
            ],
            'api_quota' => $client->getQuota(),
        ]);
    }

    #[Route('/sync/odds', method: 'POST')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncOddsPost(): Response
    {
        return $this->syncOdds();
    }

    #[Route('/sync/understat', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncUnderstat(): Response
    {
        $cacheDir = $this->cachePath('/understat');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $season = (int) date('n') >= 8 ? (int) date('Y') : ((int) date('Y') - 1);
        $client = new UnderstatClient($cacheDir);
        $sync = new UnderstatSync($this->connection, $client);
        $result = $sync->sync($season);

        return Response::json([
            'success' => true,
            'season' => $season,
            'total_players' => $result['total'],
            'matched' => $result['matched'],
            'unmatched' => $result['unmatched'],
            'unmatched_players' => $result['unmatched_players'],
        ]);
    }

    #[Route('/sync/understat', method: 'POST')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncUnderstatPost(): Response
    {
        return $this->syncUnderstat();
    }

    #[Route('/sync/season-history', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncSeasonHistory(): Response
    {
        $sync = new PlayerSync($this->connection, $this->fplClient);
        $count = $sync->syncSeasonHistory();

        return Response::json([
            'success' => true,
            'season_history_records' => $count,
        ]);
    }

    #[Route('/sync/season-history', method: 'POST')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function syncSeasonHistoryPost(): Response
    {
        return $this->syncSeasonHistory();
    }

    #[Route('/admin/sample/{gw}', method: 'GET')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function adminSample(int $gw, Request $request): Response
    {
        $service = new SampleService($this->connection, $this->fplClient, $this->cachePath('/samples'));
        $full = (string) ($request->query('full') ?? '0') === '1';

        if ($full) {
            $results = $service->sampleManagersForGameweek($gw);
        } else {
            $hasSamples = $service->hasSamplesForGameweek($gw);
            $results = [
                'has_samples' => $hasSamples,
                'message' => $hasSamples
                    ? 'Samples already exist for this gameweek'
                    : 'No samples found. Use ?full=1 to run full sample sync (takes several minutes)',
            ];
        }

        return Response::json([
            'gameweek' => $gw,
            'results' => $results,
        ]);
    }

    #[Route('/penalty-takers', method: 'GET')]
    public function getPenaltyTakers(): Response
    {
        $takers = $this->fetchAll(
            'SELECT p.id, p.web_name, p.club_id as team, p.position, p.penalty_order,
                    c.name as team_name, c.short_name as team_short
             FROM players p
             JOIN clubs c ON p.club_id = c.id
             WHERE p.penalty_order IS NOT NULL
             ORDER BY p.club_id, p.penalty_order'
        );

        return Response::json(['penalty_takers' => $takers]);
    }

    #[Route('/teams/{id}/penalty-takers', method: 'PUT')]
    #[MiddlewareAttribute(AdminAuthMiddleware::class)]
    public function setTeamPenaltyTakers(int $id, Request $request): Response
    {
        $body = $request->body();
        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid JSON body'], 400);
        }

        $takers = $body['takers'] ?? [];
        if (!is_array($takers) || $takers === []) {
            return Response::json([
                'error' => 'Body must contain "takers" array of {player_id, order}',
                'example' => ['takers' => [['player_id' => 123, 'order' => 1], ['player_id' => 456, 'order' => 2]]],
            ], 400);
        }

        $this->execute('UPDATE players SET penalty_order = NULL WHERE club_id = ?', [$id]);

        $updated = [];
        foreach ($takers as $taker) {
            if (!is_array($taker)) {
                continue;
            }

            $playerId = (int) ($taker['player_id'] ?? 0);
            $order = (int) ($taker['order'] ?? 0);
            if ($playerId <= 0 || $order < 1 || $order > 5) {
                continue;
            }

            $this->execute(
                'UPDATE players SET penalty_order = ? WHERE id = ? AND club_id = ?',
                [$order, $playerId, $id]
            );
            $updated[] = ['player_id' => $playerId, 'order' => $order];
        }

        return Response::json([
            'success' => true,
            'team_id' => $id,
            'updated' => $updated,
        ]);
    }

    private function expectedAdminToken(): string
    {
        return trim((string) $this->config->get('config.security.admin_token', ''));
    }

    /**
     * @return array<string, string>
     */
    private function parseCookieHeader(string $rawCookie): array
    {
        if ($rawCookie === '') {
            return [];
        }

        $cookies = [];
        foreach (explode(';', $rawCookie) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }
            $cookies[$name] = urldecode(trim($parts[1]));
        }

        return $cookies;
    }

    private function setCookie(string $name, string $value, bool $httpOnly, int $ttlSeconds): void
    {
        setcookie($name, $value, [
            'expires' => time() + $ttlSeconds,
            'path' => '/',
            'secure' => false,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);

        if (!isset($GLOBALS['superfpl_set_cookies']) || !is_array($GLOBALS['superfpl_set_cookies'])) {
            $GLOBALS['superfpl_set_cookies'] = [];
        }
        $GLOBALS['superfpl_set_cookies'][] = sprintf('%s=%s', $name, rawurlencode($value));
    }

    private function cachePath(string $suffix): string
    {
        $base = (string) $this->config->get('config.cache.path', dirname(__DIR__, 2) . '/cache');

        return rtrim($base, '/\\') . $suffix;
    }
}
