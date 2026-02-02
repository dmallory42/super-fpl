# Super FPL - Claude Instructions

## Project Overview

Fantasy Premier League analytics application with a PHP backend API and React/TypeScript frontend.

## Quick Start

```bash
# Start all services
cd /Users/mal/projects/super-fpl
docker-compose up -d        # API at http://localhost:8080
cd frontend && npm run dev  # Frontend at http://localhost:5173
```

## Project Structure

```
super-fpl/
├── api/                    # PHP backend
│   ├── public/index.php    # Main router - all endpoints defined here
│   ├── src/
│   │   ├── Services/       # Business logic services
│   │   └── Sync/           # FPL API data sync
│   └── data/schema.sql     # SQLite schema
├── frontend/               # React/TypeScript frontend
│   └── src/
│       ├── pages/          # Page components (4 tabs)
│       ├── components/     # Reusable components
│       ├── hooks/          # React Query hooks
│       └── api/client.ts   # API client with types
├── packages/
│   └── fpl-client/         # FPL API client library
└── deploy/                 # Docker/nginx configs
```

## Code Conventions

### PHP Backend
- PSR-4 autoloading, namespace: `SuperFPL\Api\`
- Services in `api/src/Services/` for business logic
- FplClient uses fluent API: `$fplClient->entry($id)->getRaw()`
- Database uses SQLite, schema in `api/data/schema.sql`

### TypeScript Frontend
- React functional components with hooks
- TanStack Query for data fetching (hooks in `src/hooks/`)
- Tailwind CSS for styling
- Types defined in `src/types.ts` and `src/api/client.ts`

## Building

```bash
# Frontend
cd frontend && npm run build   # TypeScript check + Vite build

# Check PHP syntax
php -l api/src/Services/SomeService.php
```

## Testing

**IMPORTANT: Use Test-Driven Development (TDD) for all new features.**

1. Write tests BEFORE implementing new functionality
2. Run tests to verify they fail (red)
3. Implement the minimum code to pass tests (green)
4. Refactor while keeping tests green

For bug fixes, write a failing test that reproduces the bug first, then fix it.

### Running Tests

```bash
# Backend tests (PHPUnit)
cd api && ./vendor/bin/phpunit

# Frontend tests (Vitest)
cd frontend && npm test

# Run specific test file
cd api && ./vendor/bin/phpunit tests/Services/GameweekServiceTest.php
cd frontend && npm test -- src/hooks/useLive.test.tsx
```

### Test Coverage Requirements

- **New API endpoints**: Add service tests in `api/tests/Services/`
- **New React components**: Add component tests with `@testing-library/react`
- **New hooks**: Test conditional fetching and data transformation
- **Utility functions**: Unit test all pure functions

### Test Structure

```php
// Backend: api/tests/Services/ExampleServiceTest.php
class ExampleServiceTest extends TestCase {
    protected function setUp(): void {
        $this->db = new Database(':memory:');
        // Create schema and test data
    }
}
```

```typescript
// Frontend: src/components/Example.test.tsx
import { render, screen } from '../test/utils'
// Use custom render with QueryClientProvider
```

## Key Features

1. **Team Analyzer** - Analyze FPL manager squads
2. **League Analyzer** - Mini-league analysis with differentials and risk
3. **Live** - Live gameweek tracker with formation pitch view
4. **Planner** - Multi-week transfer optimization with chip suggestions

## API Endpoints

Key endpoints in `api/public/index.php`:
- `GET /players` - All players with team data
- `GET /predictions/{gw}` - Point predictions for gameweek
- `GET /live/{gw}/manager/{id}` - Live points for manager
- `GET /leagues/{id}/analysis` - League analysis with comparisons
- `GET /planner/optimize?manager={id}&ft={n}` - Transfer optimization

## Database Schema

Players table key columns:
- `id`, `web_name`, `team`, `element_type` (position 1-4)
- `now_cost` (price in 0.1m units), `total_points`, `form`
- `expected_goals`, `expected_assists`, `selected_by_percent`

## After Making Changes

Always ensure services are running after code changes:
```bash
docker-compose up -d                    # Restart API if PHP changed
cd frontend && npm run build && npm run dev  # Rebuild frontend
```

## Common Issues

- **FplClient methods**: Use fluent API - `$fplClient->entry($id)->getRaw()` not `$fplClient->getEntry($id)`
- **Null checks**: Always check for undefined array keys in PHP when accessing player/squad data
- **Type mismatches**: Frontend interfaces must match API response structures
