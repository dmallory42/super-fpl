# Super FPL

Fantasy Premier League analytics app with:
- PHP API (`api/`)
- React + TypeScript frontend (`frontend/`)
- Dockerized nginx/php/cron/redis local stack

## Tech Stack

- Backend: PHP 8.2, SQLite, PHPUnit
- Frontend: React, Vite, TanStack Query, Playwright
- Infra (local): Docker Compose (`nginx`, `php`, `cron`, `redis`)

## Prerequisites

- Docker + Docker Compose
- Node.js 22+ and npm

## Quick Start

```bash
npm install
cd api && composer install && cd ..
npm run up:build
```

This will:
- Build/start Docker services on the API side
- Start frontend dev server

App URLs:
- Frontend: `http://localhost:5173`
- API (via nginx): `http://localhost:8080/api`

## Common Commands

### Start/Stop

```bash
npm run up        # start API stack + frontend dev server
npm run up:build  # rebuild containers + start
npm run down      # stop containers
```

### API-only / Frontend-only

```bash
npm run api       # start docker services only
npm run api:logs  # tail docker logs
npm run dev       # frontend dev server only
```

### Build & Tests

```bash
npm run build           # frontend build
npm run test:frontend   # frontend unit tests
npm run test:e2e        # Playwright e2e

cd api && ./vendor/bin/phpunit tests
```

## Data Sync

Manual sync entrypoints:

```bash
npm run sync:pre
npm run sync:post
npm run sync:slow
npm run sync:samples
npm run sync:all
npm run sync:snapshot
```

## Cron Schedule (UTC)

Configured in `deploy/crontab` and run by the `cron` service:

- `01:00` daily: `php cron/fixtures-refresh.php`
- `HH:05` hourly: `php cron/fixtures-post-deadline-refresh.php` (runs once per GW, 1h after deadline)
- `09:00` daily: `php cron/sync-all.php --phase=pre-deadline`
- `23:59` daily: `php cron/sync-all.php --phase=post-gameweek`
- `04:00` Monday: `php cron/sync-all.php --phase=slow`
- `12:00` Saturday: `php cron/sync-all.php --phase=samples`

Important: after changing `deploy/crontab`, rebuild/restart cron:

```bash
docker compose build cron
docker compose up -d cron
```

## Caching

- FPL client cache: file cache under `api/cache`
- API response cache: Redis-backed (with fallback bypass), keying includes DB mtime and sync version
- Cache status header for supported GET endpoints: `X-Response-Cache: HIT|MISS|BYPASS`
- Bypass per request: append `?nocache=1`
