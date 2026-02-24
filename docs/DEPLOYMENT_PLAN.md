# SuperFPL Deployment Plan

## Recommendation
Use **DigitalOcean (single Ubuntu droplet) + Docker Compose** for v1.

Reason:
- Current app uses **SQLite + cron jobs + same-origin `/api`**.
- This maps cleanly to a single host with persistent disk.
- Lowest operational complexity for launch.

For later scale, migrate DB to Postgres and split services.

---

## Target Architecture (v1)
- **DNS**: `superfpl.com` + `www.superfpl.com` -> Droplet public IP
- **Reverse proxy**: Caddy (auto TLS)
  - `/` -> static frontend build (`frontend/dist`)
  - `/api` -> PHP-FPM app (`api/public/index.php`)
- **App containers**:
  - `php` (API)
  - `cron` (scheduled sync jobs)
  - `redis` (response cache)
  - `caddy` (public entrypoint)
- **Persistent storage**:
  - `api/data/superfpl.db`
  - `api/cache/` (file cache + logs)

---

## Pre-Deployment Changes (must-do)
1. Rotate any previously exposed API keys (especially Odds API key).
2. Create `.env.production` (not committed) from `.env.production.example`.
3. Set production security env vars:
   - `SUPERFPL_APP_ENV=production`
   - `SUPERFPL_DEBUG=0`
   - `SUPERFPL_CORS_ALLOWED_ORIGINS=https://superfpl.com,https://www.superfpl.com`
   - `SUPERFPL_ADMIN_TOKEN=<long-random-token>`
4. Set Redis auth (optional but recommended):
   - `REDIS_PASSWORD=<strong-password>`
   - include password in `REDIS_URL` if used.
5. Provision server dependencies:
   - Docker + Docker Compose plugin
   - Node.js 22.12+ for frontend builds during deploy

---

## Included Deployment Files
- `docker-compose.prod.yml`
- `deploy/Caddyfile`
- `deploy/deploy-prod.sh`
- `.env.production.example`
- `.github/workflows/deploy-production.yml`

---

## Provisioning Steps (DigitalOcean)
1. Create Droplet:
   - Ubuntu 24.04 LTS
   - 2 vCPU / 4GB RAM minimum
   - 80GB+ SSD
2. Attach SSH key; disable password auth.
3. Set up firewall:
   - Allow `22`, `80`, `443`
   - Deny all others
4. Install runtime:
   - Docker + Docker Compose plugin
   - Fail2ban + unattended-upgrades
5. Clone repo to `/opt/superfpl`.
6. Create `.env.production` on server.

---

## Deployment Procedure
1. Build frontend:
   - `npm ci && npm run build`
2. Copy env template:
   - `cp .env.production.example .env.production` and fill values
3. Start/upgrade stack:
   - `docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build`
4. Verify health:
   - `curl -I https://superfpl.com`
   - `curl -I https://superfpl.com/api/health`
5. Confirm cron:
   - check `cron` container logs and `/var/log/cron/sync.log`.

---

## Security Hardening Included
- API now returns sanitized `500` errors in production (`request_id` retained for logs).
- CORS is allowlist-based via `SUPERFPL_CORS_ALLOWED_ORIGINS`.
- Admin/sync/mutation routes can be protected with `SUPERFPL_ADMIN_TOKEN`.
- Production reverse proxy adds HTTPS security headers (HSTS, no-sniff, frame deny, referrer policy).
- Redis auth can be enabled with `REDIS_PASSWORD`.

Admin token request format:
- `Authorization: Bearer <token>` or
- `X-Admin-Token: <token>`

---

## CI/CD Plan
Use GitHub Actions + manual approval to deploy `main`.

Pipeline:
1. Run existing test workflow (`npm run test`).
2. On `main` success, deploy job via SSH:
   - run `deploy/deploy-prod.sh` on the droplet
3. Post-deploy smoke tests:
   - `/api/health`
   - one representative API route (`/api/predictions/range?...`)

Required repo secrets for workflow:
- `PROD_HOST`
- `PROD_USER`
- `PROD_SSH_KEY`
- `PROD_PORT` (optional, default `22`)
- `PROD_APP_DIR` (optional, default `/opt/superfpl`)

---

## DNS + TLS
1. DNS records:
   - `A @ -> <droplet-ip>`
   - `A www -> <droplet-ip>`
2. TLS:
   - Let’s Encrypt via certbot (or Caddy automatic TLS)
3. Force HTTPS + HSTS.

---

## Backups and Recovery
- Nightly backup of:
  - `api/data/superfpl.db`
  - `api/cache/sync_version.txt` (optional)
- Keep at least 7 daily + 4 weekly backups (off-host object storage).
- Test restore monthly to a staging droplet.

---

## Monitoring and Ops
- Add uptime check for `/api/health`.
- Track:
  - container restarts
  - API error log size/growth
  - disk usage (SQLite + cache growth)
- Enable basic log rotation for caddy/php/cron logs.

---

## Rollback Plan
- Keep previous image/tag and previous git SHA.
- Rollback command:
  - `git checkout <previous-sha>`
  - `docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build`
- If DB migration changes are introduced later, require reversible migrations before deploy.

---

## Alternative Options
### Option B: DigitalOcean App Platform
Good when app is stateless. Current SQLite + cron model is not ideal unless you migrate DB and scheduled jobs first.

### Option C: Fly.io/Render/Railway
Works well after moving to Postgres + object storage + dedicated worker/cron process.

---

## Suggested Roadmap
1. Launch on single DO droplet (this plan).
2. Stabilize and measure traffic.
3. Migrate SQLite -> Postgres.
4. Split web/api/worker and move to managed platform if desired.
