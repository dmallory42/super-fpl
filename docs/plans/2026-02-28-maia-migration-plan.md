# Maia Framework Migration - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Migrate the Super FPL PHP backend from procedural routing to the Maia framework, replacing routing, middleware, DI, and database layers.

**Architecture:** Big-bang migration on a feature branch. Attribute-based controllers replace the 2,400-line index.php. Maia ORM models replace raw SQL for CRUD. Services keep business logic. Raw SQL via Connection::query() for complex queries until Maia gaps are filled.

**Tech Stack:** Maia Framework (Core + ORM + Auth), PHP 8.2+, SQLite, Redis (Predis), PHPUnit

**Design doc:** `docs/plans/2026-02-28-maia-migration-design.md`

---

## Task 1: Create Feature Branch & Add Maia Dependency

**Files:**
- Modify: `api/composer.json`

**Step 1: Create feature branch**

```bash
git checkout -b feat/maia-migration
```

**Step 2: Add Maia as a path repository dependency**

Edit `api/composer.json` to add the Maia repository and require it:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/fpl-client"
        },
        {
            "type": "path",
            "url": "../../maia"
        }
    ],
    "require": {
        "php": ">=8.2",
        "superfpl/fpl-client": "*",
        "predis/predis": "^2.3",
        "maia/framework": "*"
    }
}
```

**Step 3: Install dependencies**

```bash
docker compose exec php composer update
```

Expected: Maia packages installed via symlink. Verify with:
```bash
docker compose exec php php -r "require 'vendor/autoload.php'; echo Maia\Core\App::class . PHP_EOL;"
```
Expected output: `Maia\Core\App`

**Step 4: Commit**

```bash
git add api/composer.json api/composer.lock
git commit -m "chore: add maia framework as dependency"
```

---

## Task 2: Create Bootstrap & Entry Point

**Files:**
- Create: `api/bootstrap.php`
- Modify: `api/public/index.php` (will be replaced later, keep working for now)

**Step 1: Create bootstrap.php**

Create `api/bootstrap.php` that initializes the Maia App with the existing config, database connection, and services:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Maia\Core\App;
use Maia\Orm\Connection;

// Load existing config
$config = require __DIR__ . '/config/config.php';

// Create Maia app
$app = App::create();

// Register database connection with SQLite pragmas
$connection = new Connection('sqlite:' . $config['database']['path']);
$connection->execute('PRAGMA foreign_keys = ON');
$connection->execute('PRAGMA busy_timeout = 5000');
$connection->execute('PRAGMA journal_mode = WAL');
$connection->execute('PRAGMA synchronous = NORMAL');

\Maia\Orm\Model::setConnection($connection);

// Register config and connection in container
$app->container()->instance('config', $config);
$app->container()->instance(Connection::class, $connection);

// Redis client (optional)
$redisClient = null;
$redisUrl = getenv('REDIS_URL') ?: null;
$redisHost = getenv('REDIS_HOST') ?: null;
if ($redisUrl || $redisHost) {
    try {
        $redisClient = $redisUrl
            ? new \Predis\Client($redisUrl)
            : new \Predis\Client([
                'host' => $redisHost,
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
                'password' => getenv('REDIS_PASSWORD') ?: null,
            ]);
        $redisClient->ping();
    } catch (\Throwable $e) {
        $redisClient = null;
    }
}
$app->container()->instance('redis', $redisClient);

// FPL Client
$fplClient = new \SuperFPL\FplClient\FplClient(
    cachePath: ($config['cache']['path'] ?? __DIR__ . '/cache') . '/fpl',
    rateLimitDir: $config['fpl']['rate_limit_dir'] ?? '/tmp/fpl-rate-limit',
    connectTimeout: $config['fpl']['connect_timeout'] ?? 8,
    requestTimeout: $config['fpl']['request_timeout'] ?? 15,
);
$app->container()->instance(\SuperFPL\FplClient\FplClient::class, $fplClient);

return $app;
```

**Step 2: Verify bootstrap loads**

```bash
docker compose exec php php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap.php';
echo get_class(\$app) . PHP_EOL;
echo get_class(\$app->container()->resolve(Maia\Orm\Connection::class)) . PHP_EOL;
"
```

Expected:
```
Maia\Core\App
Maia\Orm\Connection
```

**Step 3: Commit**

```bash
git add api/bootstrap.php
git commit -m "feat: add maia bootstrap with DI container wiring"
```

---

## Task 3: Create ORM Models (Core Tables)

**Files:**
- Create: `api/src/Models/Club.php`
- Create: `api/src/Models/Player.php`
- Create: `api/src/Models/Fixture.php`
- Create: `api/src/Models/Manager.php`
- Create: `api/src/Models/ManagerPick.php`
- Create: `api/src/Models/ManagerHistory.php`
- Create: `api/src/Models/League.php`
- Create: `api/src/Models/LeagueMember.php`

**Step 1: Write test for Club model**

Create `api/tests/Models/ClubTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Models;

use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Models\Club;

class ClubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db()->execute('CREATE TABLE clubs (
            id INTEGER PRIMARY KEY,
            name TEXT,
            short_name TEXT,
            strength_attack_home INTEGER,
            strength_attack_away INTEGER,
            strength_defence_home INTEGER,
            strength_defence_away INTEGER
        )');

        $this->db()->execute(
            "INSERT INTO clubs (id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away) VALUES (1, 'Arsenal', 'ARS', 1300, 1250, 1350, 1300)"
        );
    }

    public function testFindClub(): void
    {
        $club = Club::find(1);
        $this->assertNotNull($club);
        $this->assertEquals('Arsenal', $club->name);
        $this->assertEquals('ARS', $club->short_name);
    }

    public function testQueryAll(): void
    {
        $this->db()->execute(
            "INSERT INTO clubs (id, name, short_name, strength_attack_home, strength_attack_away, strength_defence_home, strength_defence_away) VALUES (2, 'Chelsea', 'CHE', 1200, 1150, 1250, 1200)"
        );

        $clubs = Club::query()->get();
        $this->assertCount(2, $clubs);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd api && ./vendor/bin/phpunit tests/Models/ClubTest.php
```

Expected: FAIL (class not found)

**Step 3: Create Club model**

Create `api/src/Models/Club.php`:

```php
<?php

declare(strict_types=1);

namespace SuperFPL\Api\Models;

use Maia\Orm\Attributes\HasMany;
use Maia\Orm\Attributes\Table;
use Maia\Orm\Model;

#[Table(name: 'clubs')]
class Club extends Model
{
    public int $id;
    public ?string $name = null;
    public ?string $short_name = null;
    public ?int $strength_attack_home = null;
    public ?int $strength_attack_away = null;
    public ?int $strength_defence_home = null;
    public ?int $strength_defence_away = null;

    #[HasMany(relatedClass: Player::class, foreignKey: 'club_id')]
    public array $players = [];
}
```

**Step 4: Run test to verify it passes**

```bash
cd api && ./vendor/bin/phpunit tests/Models/ClubTest.php
```

Expected: PASS

**Step 5: Create remaining models following the same pattern**

Create each model with properties matching the schema columns and appropriate relationships. Key models:

**`api/src/Models/Player.php`** — BelongsTo Club. Large number of columns (web_name, now_cost, form, total_points, expected_goals, etc. from schema). Include `xmins_override` and `penalty_order`.

**`api/src/Models/Fixture.php`** — BelongsTo Club (home_club_id), BelongsTo Club (away_club_id). Columns: gameweek, kickoff_time, home_score, away_score, finished, etc.

**`api/src/Models/Manager.php`** — HasMany ManagerPick, HasMany ManagerHistory. Columns from managers table.

**`api/src/Models/ManagerPick.php`** — BelongsTo Manager, BelongsTo Player. Composite key (manager_id + gameweek + player_id).

**`api/src/Models/ManagerHistory.php`** — BelongsTo Manager. Columns: gameweek, points, rank, transfers_cost, bank, etc.

**`api/src/Models/League.php`** — HasMany LeagueMember. Columns from leagues table.

**`api/src/Models/LeagueMember.php`** — BelongsTo League, BelongsTo Manager.

Write a test for each model verifying find() and basic queries work.

**Step 6: Run all model tests**

```bash
cd api && ./vendor/bin/phpunit tests/Models/
```

Expected: All pass

**Step 7: Commit**

```bash
git add api/src/Models/ api/tests/Models/
git commit -m "feat: add core ORM models with relationships"
```

---

## Task 4: Create ORM Models (Prediction & Data Tables)

**Files:**
- Create: `api/src/Models/PlayerPrediction.php`
- Create: `api/src/Models/PredictionSnapshot.php`
- Create: `api/src/Models/GameweekHistory.php`
- Create: `api/src/Models/FixtureOdds.php`
- Create: `api/src/Models/GoalscorerOdds.php`
- Create: `api/src/Models/AssistOdds.php`
- Create: `api/src/Models/SamplePick.php`
- Create: `api/src/Models/PlayerSeasonHistory.php`

**Step 1: Create models with tests**

Follow the same pattern as Task 3. Key details:

**`PlayerPrediction`** — BelongsTo Player. Columns: gameweek, predicted_points, expected_mins, prob_any, prob_60, predicted_if_fit, if_fit_breakdown_json, breakdown_json.

**`GameweekHistory`** — BelongsTo Player. Table: `player_gameweek_history`. Columns: player_id, gameweek, minutes, goals_scored, assists, clean_sheets, total_points, etc.

**`FixtureOdds`** — BelongsTo Fixture. Columns: home_win_prob, draw_prob, away_win_prob, home_cs_prob, away_cs_prob, etc.

**`SamplePick`** — BelongsTo Player. Columns: gameweek, player_id, tier, pick_count, captain_count, etc.

**Step 2: Run all model tests**

```bash
cd api && ./vendor/bin/phpunit tests/Models/
```

Expected: All pass

**Step 3: Commit**

```bash
git add api/src/Models/ api/tests/Models/
git commit -m "feat: add prediction and data ORM models"
```

---

## Task 5: Create Middleware

**Files:**
- Create: `api/src/Middleware/AdminAuthMiddleware.php`
- Create: `api/src/Middleware/ResponseCacheMiddleware.php`
- Create: `api/tests/Middleware/AdminAuthMiddlewareTest.php`
- Create: `api/tests/Middleware/ResponseCacheMiddlewareTest.php`

**Step 1: Write AdminAuthMiddleware test**

```php
<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Middleware;

use Maia\Core\Testing\TestCase;
use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use Maia\Core\Routing\Middleware as MiddlewareAttr;
use SuperFPL\Api\Middleware\AdminAuthMiddleware;

#[Controller(prefix: '/test')]
class AdminTestController
{
    #[Route(path: '/public', method: 'GET')]
    public function publicRoute(): array
    {
        return ['ok' => true];
    }

    #[Route(path: '/admin', method: 'GET')]
    #[MiddlewareAttr(AdminAuthMiddleware::class)]
    public function adminRoute(): array
    {
        return ['admin' => true];
    }
}

class AdminAuthMiddlewareTest extends TestCase
{
    protected function controllers(): array
    {
        return [AdminTestController::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Register middleware with a known admin token
        $this->app->container()->instance('config', [
            'security' => ['admin_token' => 'test-admin-token'],
        ]);
    }

    public function testPublicRouteAccessible(): void
    {
        $response = $this->get('/test/public');
        $response->assertStatus(200);
    }

    public function testAdminRouteRejectsWithoutAuth(): void
    {
        $response = $this->get('/test/admin');
        $response->assertStatus(401);
    }

    public function testAdminRouteAcceptsValidCookie(): void
    {
        $cookieValue = hash('sha256', 'test-admin-token');
        $response = $this->withHeader('Cookie', "superfpl_admin=$cookieValue")
            ->withHeader('X-XSRF-Token', $cookieValue)
            ->get('/test/admin');
        $response->assertStatus(200);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd api && ./vendor/bin/phpunit tests/Middleware/AdminAuthMiddlewareTest.php
```

**Step 3: Implement AdminAuthMiddleware**

Port the admin token/cookie/CSRF logic from `index.php` into a Maia middleware class. The middleware:
- Reads `superfpl_admin` cookie
- Validates against SHA-256 hash of `SUPERFPL_ADMIN_TOKEN`
- Checks `X-XSRF-Token` header matches
- Returns 401 if invalid

**Step 4: Implement ResponseCacheMiddleware**

Port the `withResponseCache()` function from `index.php` into a middleware. Since TTL varies per-route, use a configurable approach:
- Accept TTL and namespace as constructor params
- Check Redis for cached response (key = namespace + URI + DB mtime)
- Return cached response or proceed and cache the result
- Respect `?nocache=1` and `?refresh=1` query params
- Add `X-Response-Cache: HIT|MISS|BYPASS` header

Note: This middleware will be instantiated per-route via the container with different TTL values, or via a factory pattern. Exact implementation depends on Maia's middleware resolution — may need to pass TTL as route-level config.

**Step 5: Run tests**

```bash
cd api && ./vendor/bin/phpunit tests/Middleware/
```

Expected: All pass

**Step 6: Commit**

```bash
git add api/src/Middleware/ api/tests/Middleware/
git commit -m "feat: add admin auth and response cache middleware"
```

---

## Task 6: Create HealthController & PlayerController

**Files:**
- Create: `api/src/Controllers/HealthController.php`
- Create: `api/src/Controllers/PlayerController.php`
- Create: `api/tests/Controllers/HealthControllerTest.php`
- Create: `api/tests/Controllers/PlayerControllerTest.php`

**Step 1: Write HealthController test**

```php
<?php

declare(strict_types=1);

namespace SuperFPL\Api\Tests\Controllers;

use Maia\Core\Testing\TestCase;
use SuperFPL\Api\Controllers\HealthController;

class HealthControllerTest extends TestCase
{
    protected function controllers(): array
    {
        return [HealthController::class];
    }

    public function testHealthEndpoint(): void
    {
        $response = $this->get('/health');
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertEquals('ok', $json['status']);
        $this->assertArrayHasKey('timestamp', $json);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd api && ./vendor/bin/phpunit tests/Controllers/HealthControllerTest.php
```

**Step 3: Implement HealthController**

```php
<?php

declare(strict_types=1);

namespace SuperFPL\Api\Controllers;

use Maia\Core\Routing\Controller;
use Maia\Core\Routing\Route;
use Maia\Core\Http\Response;
use Maia\Orm\Connection;

#[Controller(prefix: '')]
class HealthController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route(path: '/health', method: 'GET')]
    public function health(): Response
    {
        $dbStatus = 'ok';
        $message = null;

        try {
            $result = $this->connection->query('PRAGMA quick_check');
            if (($result[0]['quick_check'] ?? '') !== 'ok') {
                $dbStatus = 'error';
                $message = $result[0]['quick_check'] ?? 'Unknown error';
            }
        } catch (\Throwable $e) {
            $dbStatus = 'error';
            $message = $e->getMessage();
        }

        $status = $dbStatus === 'ok' ? 'ok' : 'degraded';
        $httpCode = $status === 'ok' ? 200 : 503;

        return Response::json([
            'status' => $status,
            'timestamp' => date('c'),
            'checks' => [
                'database' => [
                    'status' => $dbStatus,
                    'checked_at' => date('c'),
                    'message' => $message,
                ],
            ],
        ], $httpCode);
    }
}
```

**Step 4: Run test**

```bash
cd api && ./vendor/bin/phpunit tests/Controllers/HealthControllerTest.php
```

Expected: PASS

**Step 5: Write PlayerController test and implement**

Test that `GET /players` returns players array with expected shape. Test `GET /players/{id}` returns a single player. Test query param filters (position, team).

The controller injects the existing `PlayerService` (initially keeping raw DB calls) and delegates to it.

```php
#[Controller(prefix: '/players')]
class PlayerController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route(path: '', method: 'GET')]
    public function index(Request $request): Response
    {
        $service = new PlayerService(/* adapt for Connection */);
        // ...
        return Response::json(['players' => $players, 'teams' => $teams]);
    }

    #[Route(path: '/{id}', method: 'GET')]
    public function show(int $id): Response
    {
        // ...
    }

    #[Route(path: '/{id}/xmins', method: 'PUT')]
    #[Middleware(AdminAuthMiddleware::class)]
    public function setXMins(int $id, Request $request): Response
    {
        // ...
    }
}
```

**Step 6: Run tests**

```bash
cd api && ./vendor/bin/phpunit tests/Controllers/
```

**Step 7: Commit**

```bash
git add api/src/Controllers/ api/tests/Controllers/
git commit -m "feat: add health and player controllers"
```

---

## Task 7: Create Remaining Controllers

**Files:**
- Create: `api/src/Controllers/FixtureController.php`
- Create: `api/src/Controllers/ManagerController.php`
- Create: `api/src/Controllers/LeagueController.php`
- Create: `api/src/Controllers/PredictionController.php`
- Create: `api/src/Controllers/LiveController.php`
- Create: `api/src/Controllers/TransferController.php`
- Create: `api/src/Controllers/AdminController.php`
- Create: tests for each

Follow the same TDD pattern as Task 6 for each controller. Port the handler logic from `index.php` into controller methods.

**Key routing details:**

```php
// FixtureController
#[Controller(prefix: '/fixtures')]
// GET /fixtures → index()
// GET /fixtures/status → status()

// ManagerController
#[Controller(prefix: '/managers')]
// GET /managers/{id} → show(int $id)
// GET /managers/{id}/picks/{gw} → picks(int $id, int $gw)
// GET /managers/{id}/history → history(int $id)
// GET /managers/{id}/season-analysis → seasonAnalysis(int $id)

// LeagueController
#[Controller(prefix: '/leagues')]
// GET /leagues/{id} → show(int $id)
// GET /leagues/{id}/standings → standings(int $id)
// GET /leagues/{id}/analysis → analysis(int $id)
// GET /leagues/{id}/season-analysis → seasonAnalysis(int $id)

// PredictionController
#[Controller(prefix: '/predictions')]
// GET /predictions/{gw} → index(int $gw)
// GET /predictions/{gw}/accuracy → accuracy(int $gw)
// GET /predictions/{gw}/player/{id} → player(int $gw, int $id)
// GET /predictions/range → range(Request $request)
// GET /predictions/methodology → methodology()

// LiveController
#[Controller(prefix: '/live')]
// GET /live/current → current()
// GET /live/{gw} → gameweek(int $gw)
// GET /live/{gw}/manager/{id} → manager(int $gw, int $id)
// GET /live/{gw}/manager/{id}/enhanced → managerEnhanced(int $gw, int $id)
// GET /live/{gw}/bonus → bonus(int $gw)
// GET /live/{gw}/samples → samples(int $gw)

// TransferController — note dual prefix
#[Controller(prefix: '')]
// GET /transfers/suggest → suggest(Request $request)
// GET /transfers/simulate → simulate(Request $request)
// GET /transfers/targets → targets(Request $request)
// GET /planner/optimize → optimize(Request $request)
// GET /planner/chips/suggest → chipSuggest(Request $request)

// AdminController
#[Controller(prefix: '/admin')]
// POST /admin/login → login(Request $request)
// GET /admin/session → session()
// POST /sync/players, /sync/fixtures, etc. → sync methods (admin-protected)
// GET /penalty-takers → getPenaltyTakers()
// PUT /teams/{id}/penalty-takers → setTeamPenaltyTakers(int $id, Request $request)
```

**Step 1: Write tests for each controller** (at minimum: one test per endpoint verifying status code and response shape)

**Step 2: Implement controllers**

Initially, controllers call existing service classes directly. Services still use the old `Database` class internally — this gets refactored in Task 9.

**Step 3: Run all controller tests**

```bash
cd api && ./vendor/bin/phpunit tests/Controllers/
```

**Step 4: Commit after each controller or in batches of 2-3**

```bash
git commit -m "feat: add fixture and manager controllers"
git commit -m "feat: add league and prediction controllers"
git commit -m "feat: add live and transfer controllers"
git commit -m "feat: add admin controller with sync endpoints"
```

---

## Task 8: Wire Up Global Middleware & Replace index.php

**Files:**
- Modify: `api/bootstrap.php` (add middleware registration)
- Modify: `api/public/index.php` (replace with Maia entry point)

**Step 1: Update bootstrap.php to register middleware and controllers**

Add to the end of `bootstrap.php`:

```php
use Maia\Auth\CorsMiddleware;
use Maia\Auth\SecurityHeadersMiddleware;
use SuperFPL\Api\Middleware\ResponseCacheMiddleware;

// Global middleware
$app->addMiddleware(new CorsMiddleware($config['security']['cors_allowed_origins']));
$app->addMiddleware(new SecurityHeadersMiddleware());

// Register all controllers
$app->registerController(\SuperFPL\Api\Controllers\HealthController::class);
$app->registerController(\SuperFPL\Api\Controllers\PlayerController::class);
$app->registerController(\SuperFPL\Api\Controllers\FixtureController::class);
$app->registerController(\SuperFPL\Api\Controllers\ManagerController::class);
$app->registerController(\SuperFPL\Api\Controllers\LeagueController::class);
$app->registerController(\SuperFPL\Api\Controllers\PredictionController::class);
$app->registerController(\SuperFPL\Api\Controllers\LiveController::class);
$app->registerController(\SuperFPL\Api\Controllers\TransferController::class);
$app->registerController(\SuperFPL\Api\Controllers\AdminController::class);
```

**Step 2: Replace index.php**

Replace the 2,400-line `api/public/index.php` with:

```php
<?php

declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

$request = \Maia\Core\Http\Request::capture();
$response = $app->handle($request);
$response->send();
```

**Step 3: Test against running Docker container**

```bash
# Restart PHP-FPM to pick up changes
docker compose restart php

# Test health endpoint
curl -s http://localhost:8080/api/health | jq .

# Test players endpoint
curl -s http://localhost:8080/api/players | jq '.players | length'

# Test a parameterized route
curl -s http://localhost:8080/api/players/1 | jq .
```

Verify responses match the old API format.

**Step 4: Run all tests**

```bash
cd api && ./vendor/bin/phpunit
```

**Step 5: Commit**

```bash
git add api/bootstrap.php api/public/index.php
git commit -m "feat: replace procedural router with maia app entry point"
```

---

## Task 9: Refactor Services to Use ORM Models

**Files:**
- Modify: `api/src/Services/PlayerService.php`
- Modify: `api/src/Services/TeamService.php`
- Modify: `api/src/Services/FixtureService.php`
- Modify: `api/src/Services/GameweekService.php`
- Modify: `api/src/Services/ManagerService.php`
- Modify: `api/src/Services/LeagueService.php`
- Modify: `api/src/Services/PredictionService.php`
- Modify: other services as applicable

**Step 1: Refactor PlayerService**

Replace raw SQL with ORM queries where possible:

Before:
```php
public function getAll(array $filters = []): array
{
    $sql = 'SELECT id, web_name, ... FROM players';
    // manual WHERE building
    return $this->db->fetchAll($sql, $params);
}
```

After:
```php
public function getAll(array $filters = []): array
{
    $query = Player::query()->select('id', 'web_name', ...);

    if (isset($filters['position'])) {
        $query->where('position', $filters['position']);
    }
    if (isset($filters['team'])) {
        $query->where('club_id', $filters['team']);
    }

    return $query->orderBy('total_points', 'desc')->get();
}
```

For complex queries (aggregations, joins, subqueries), keep raw SQL via `Connection::query()`. Document each raw SQL call with a comment noting the Maia gap (e.g., `// Maia gap: needs groupBy() support`).

**Step 2: Update service constructors**

Change from accepting `Database` to accepting `Connection` (or use `Model::connection()` directly):

```php
public function __construct(
    private readonly Connection $connection
) {
}
```

**Step 3: Run existing service tests**

Adapt tests to use Maia's in-memory SQLite connection instead of the old Database class. Tests should verify the same behavior.

```bash
cd api && ./vendor/bin/phpunit tests/Services/
```

**Step 4: Run full test suite**

```bash
cd api && ./vendor/bin/phpunit
```

**Step 5: Commit per service or in small batches**

```bash
git commit -m "refactor: migrate PlayerService and TeamService to ORM"
git commit -m "refactor: migrate FixtureService and GameweekService to ORM"
# etc.
```

---

## Task 10: Refactor Sync Classes

**Files:**
- Modify: `api/src/Sync/PlayerSync.php`
- Modify: `api/src/Sync/FixtureSync.php`
- Modify: `api/src/Sync/ManagerSync.php`
- Modify: `api/src/Sync/OddsSync.php`
- Modify: `api/src/Sync/UnderstatSync.php`

**Step 1: Update sync constructors**

Replace `Database $db` with `Connection $connection`:

```php
public function __construct(
    private readonly Connection $connection,
    private readonly FplClient $fplClient
) {
}
```

**Step 2: Replace upsert calls with raw SQL**

The old `$db->upsert()` becomes raw `INSERT OR REPLACE`:

```php
// Before
$this->db->upsert('fixtures', $data, ['id']);

// After — Maia gap: no upsert() support
$columns = implode(', ', array_keys($data));
$placeholders = implode(', ', array_fill(0, count($data), '?'));
$this->connection->execute(
    "INSERT OR REPLACE INTO fixtures ($columns) VALUES ($placeholders)",
    array_values($data)
);
```

Consider extracting a helper method for this pattern since it's used heavily.

**Step 3: Update cron scripts**

Update `cron/sync-all.php` and individual cron scripts to use the new bootstrap:

```php
$app = require __DIR__ . '/../bootstrap.php';
$connection = $app->container()->resolve(Connection::class);
$fplClient = $app->container()->resolve(FplClient::class);

$sync = new FixtureSync($connection, $fplClient);
```

**Step 4: Test manually**

```bash
docker compose exec php php cron/sync-all.php --phase=pre-deadline
```

Verify output matches expected sync counts.

**Step 5: Commit**

```bash
git add api/src/Sync/ api/cron/
git commit -m "refactor: migrate sync classes to maia connection"
```

---

## Task 11: Migrate Tests to Maia TestCase

**Files:**
- Modify: all files in `api/tests/`

**Step 1: Update existing test base**

Convert tests from using the old `Database(':memory:')` to Maia's `TestCase` with `$this->db()`:

Before:
```php
use PHPUnit\Framework\TestCase;
use SuperFPL\Api\Database;

class SomeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        // create schema manually
    }
}
```

After:
```php
use Maia\Core\Testing\TestCase;

class SomeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // create schema using $this->db()->execute()
    }
}
```

**Step 2: Add API contract tests**

For each endpoint, verify the response shape matches what the frontend expects. These are integration tests using Maia's HTTP testing:

```php
public function testPlayersEndpointShape(): void
{
    // Seed test data
    $this->db()->execute("INSERT INTO clubs ...");
    $this->db()->execute("INSERT INTO players ...");

    $response = $this->get('/players');
    $response->assertStatus(200);

    $json = $response->json();
    $this->assertArrayHasKey('players', $json);
    $this->assertArrayHasKey('teams', $json);

    $player = $json['players'][0];
    $this->assertArrayHasKey('id', $player);
    $this->assertArrayHasKey('web_name', $player);
    $this->assertArrayHasKey('now_cost', $player);
    $this->assertArrayHasKey('element_type', $player);
}
```

**Step 3: Run full test suite**

```bash
cd api && ./vendor/bin/phpunit
```

**Step 4: Commit**

```bash
git add api/tests/
git commit -m "refactor: migrate tests to maia TestCase"
```

---

## Task 12: Cleanup

**Files:**
- Delete: `api/src/Database.php` (replaced by Maia Connection)
- Modify: `api/bootstrap.php` (remove any remaining old references)
- Modify: `deploy/docker-entrypoint.sh` (update migration command if needed)

**Step 1: Remove old Database class**

Delete `api/src/Database.php`. Search for any remaining references:

```bash
grep -r "use SuperFPL\\Api\\Database" api/src/ api/tests/
grep -r "new Database" api/src/ api/tests/ api/cron/
```

Fix any remaining references.

**Step 2: Update Docker entrypoint**

If the entrypoint runs `bin/migrate.php`, update it to use Maia's Migrator or keep the existing migration approach if schema.sql is still used.

**Step 3: Smoke test the full stack**

```bash
# Rebuild and restart
docker compose up -d --build

# Run all tests
cd api && ./vendor/bin/phpunit

# Start frontend and verify it works
cd frontend && npm run dev
```

Open the frontend and test each page (Team Analyzer, League Analyzer, Live, Planner).

**Step 4: Run frontend tests**

```bash
cd frontend && npm test -- --run
```

All 178+ frontend tests should pass since the API contract is unchanged.

**Step 5: Commit**

```bash
git add -A
git commit -m "chore: remove old Database class and clean up migration"
```

---

## Task 13: Document Maia Gaps

**Files:**
- Create: `docs/maia-gaps.md`

**Step 1: Create gaps document**

Compile all `// Maia gap:` comments and framework limitations encountered during migration:

```markdown
# Maia Framework Gaps

Discovered during Super FPL backend migration (2026-02-28).

## High Priority
- **QueryBuilder: groupBy() / having()** — needed for aggregation queries
- **QueryBuilder: upsert()** — needed for all sync operations (currently ~50 INSERT OR REPLACE calls)

## Medium Priority
- **QueryBuilder: whereRaw()** — needed for complex WHERE clauses with expressions
- **QueryBuilder: join()** — needed for multi-table queries
- **Response caching middleware** — had to build custom; could be a framework feature
- **Connection: SQLite pragma config** — currently requires raw SQL at boot

## Low Priority
- **Schema: index()** — indexes created via raw SQL in migrations
- **Schema: foreignKey()** — foreign keys defined via raw SQL
- **Model: composite primary keys** — manager_picks uses (manager_id, gameweek, player_id)
```

**Step 2: Commit**

```bash
git add docs/maia-gaps.md
git commit -m "docs: document maia framework gaps from migration"
```

---

## Task 14: Write Migration Benefits & Tradeoffs Report

**Files:**
- Create: `docs/plans/2026-02-28-maia-migration-report.md`

**Step 1: Write the report**

After completing the migration, write a report assessing:

1. **Quantitative changes** — lines of code before/after, file count, test count
2. **Architecture improvements** — what got better (routing clarity, DI, testability, code organization)
3. **Framework validation** — how well Maia handled a real application
4. **Gaps impact** — how many raw SQL workarounds were needed, which gaps hurt most
5. **Developer experience** — ease of migration, pain points, documentation gaps
6. **Performance** — any measurable differences (response times, memory usage)
7. **Recommendation** — is the migration worth it? What should Maia prioritize?

**Step 2: Commit**

```bash
git add docs/plans/2026-02-28-maia-migration-report.md
git commit -m "docs: add maia migration benefits and tradeoffs report"
```

---

## Task 15: Update Project Documentation

**Files:**
- Modify: `CLAUDE.md` (update backend section)
- Modify: `api/README.md` if it exists

**Step 1: Update CLAUDE.md**

Update the PHP Backend section to reflect the new Maia-based architecture:
- New directory structure (Controllers, Models, Middleware)
- New patterns (attribute routing, DI container, ORM queries)
- Updated test commands
- Remove references to procedural index.php routing

**Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update project docs for maia migration"
```
