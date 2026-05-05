# Boss888 / LevelUp Growth — Production Deploy Runbook
# Version: 1.0.2 | Updated: 2026-04-05
# Target: Digital Ocean Frankfurt (app.levelupgrowth.io) + Railway Redis

---

## CRITICAL RULES — READ BEFORE ANYTHING

1. **NEVER run `php artisan migrate:fresh` on production.**  
   `migrate:fresh` drops all tables and all data. It is a staging-only command.
   Production uses `php artisan migrate --force` which applies only new migrations.

2. **ALWAYS take a database backup before any deploy.**  
   The baseline.sql workflow below is the safety net — use it every time.

3. **Run commands as `www-data` or with `sudo -u www-data`** unless stated otherwise.
   File ownership matters for storage and bootstrap/cache directories.

---

## PART 1 — FIRST DEPLOY (fresh server, no existing DB)

### 1.1 — Upload and extract

```bash
cd /var/www
unzip boss888-laravel-COMPLETE-v1.0.2.zip
mv boss888-patch levelup
cd /var/www/levelup
```

### 1.2 — Set permissions

```bash
chown -R www-data:www-data /var/www/levelup
chmod -R 755 /var/www/levelup
chmod -R 775 storage bootstrap/cache
```

### 1.3 — Configure environment

```bash
cp .env.example .env
nano .env
```

Fill in every blank value. Minimum required before migration:

```ini
APP_KEY=                        # leave blank — generated in 1.4
APP_ENV=production
APP_URL=https://app.levelupgrowth.io

DB_HOST=your-do-mysql-host
DB_PORT=25060
DB_DATABASE=levelup_production
DB_USERNAME=levelup
DB_PASSWORD=your-db-password

QUEUE_CONNECTION=redis          # MUST be redis — supervisor worker expects this
REDIS_URL=redis://default:password@railway-host:port
REDIS_HOST=railway-redis-host
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379

JWT_SECRET=                     # generate: openssl rand -hex 64
RUNTIME_SECRET=                 # generate: openssl rand -hex 32 (shared with Railway)

DEEPSEEK_API_KEY=your-key
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

### 1.4 — Install dependencies and generate keys

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate
```

Generate the JWT secret (not handled by artisan key:generate):
```bash
php artisan tinker --execute="echo 'JWT_SECRET=' . bin2hex(random_bytes(64));"
```
Paste the output into `.env`.

### 1.5 — Run migrations and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

Expected: 50 migrations run, zero errors. All migrations are idempotent —
if this step is interrupted and re-run, it will not create duplicate columns.

### 1.6 — Cache for production

Always clear before caching. On a first deploy there is nothing to clear,
but running clear commands is safe and idempotent — they never fail on an empty cache.

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 1.7 — Set up queue workers (Supervisor — recommended)

Supervisor keeps workers running across reboots and auto-restarts on crash.

```bash
sudo cp config/supervisor/boss888-worker.conf /etc/supervisor/conf.d/levelup-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start levelup-worker:*
```

Verify workers are running:
```bash
sudo supervisorctl status levelup-worker:*
```

**Alternative: systemd** (if Supervisor is not available)

Create `/etc/systemd/system/levelup-worker.service`:

```ini
[Unit]
Description=LevelUp Growth Queue Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/levelup
ExecStart=/usr/bin/php artisan queue:work redis \
    --queue=tasks-high,tasks,tasks-low,default \
    --sleep=3 --tries=4 --backoff=8,16,32,64 \
    --max-time=3600 --memory=256
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable levelup-worker
sudo systemctl start levelup-worker
sudo systemctl status levelup-worker
```

### 1.8 — Configure nginx

```bash
sudo cp config/deploy/levelup-nginx.conf /etc/nginx/sites-available/levelup
sudo ln -s /etc/nginx/sites-available/levelup /etc/nginx/sites-enabled/levelup
sudo nginx -t
sudo systemctl reload nginx
```

### 1.9 — Take the baseline snapshot

After the first successful deploy, immediately capture the clean schema:

```bash
mysqldump \
  -h $DB_HOST \
  -P $DB_PORT \
  -u $DB_USERNAME \
  -p$DB_PASSWORD \
  --single-transaction \
  --routines \
  --triggers \
  $DB_DATABASE > /var/www/backups/baseline_$(date +%Y%m%d_%H%M%S).sql

echo "Baseline saved"
```

Keep this file. Every future server provision starts from baseline.sql, not migrate:fresh.

---

## PART 2 — SUBSEQUENT DEPLOYS (updating an existing running server)

### 2.1 — Pre-deploy backup (MANDATORY — do this first, always)

```bash
mkdir -p /var/www/backups

mysqldump \
  -h $DB_HOST \
  -P $DB_PORT \
  -u $DB_USERNAME \
  -p$DB_PASSWORD \
  --single-transaction \
  $DB_DATABASE > /var/www/backups/pre_deploy_$(date +%Y%m%d_%H%M%S).sql

echo "Backup complete. Proceeding with deploy."
```

If anything goes wrong after this point, restore with:
```bash
mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE \
  < /var/www/backups/pre_deploy_TIMESTAMP.sql
```

### 2.2 — Upload new release files

```bash
# Upload boss888-laravel-COMPLETE-v1.0.2.zip to /var/www/releases/
cd /var/www/releases
unzip -o boss888-laravel-COMPLETE-v1.0.2.zip -d release_$(date +%Y%m%d)
```

### 2.3 — Sync changed files to live directory

```bash
RELEASE_DIR=/var/www/releases/release_$(date +%Y%m%d)/boss888-patch
LIVE_DIR=/var/www/levelup

rsync -av --exclude='.env' \
          --exclude='vendor/' \
          --exclude='node_modules/' \
          --exclude='storage/logs/' \
          --exclude='storage/app/' \
          $RELEASE_DIR/ $LIVE_DIR/
```

The `--exclude='.env'` is critical — never overwrite the live environment file.

### 2.4 — Install/update dependencies (if composer.json changed)

```bash
cd /var/www/levelup
composer install --no-dev --optimize-autoloader --no-interaction
```

### 2.5 — Verify DB state, then run migrations

⚠️ **Never run migrations against an unknown DB state.**

Before migrating, confirm which migrations have already run:

```bash
php artisan migrate:status
```

Read the output. Every migration should show `[✓] Ran`. If any show `[✗] Pending`
that you do not recognise, or if any show as `Ran` on a DB that was not previously
migrated with this codebase, **stop and take a backup** before proceeding.
Running `migrate --force` against an unexpected DB state can corrupt schema or data.

Only proceed if the pending migrations are the ones you expect from this release:

```bash
php artisan migrate --force
```

All migrations are idempotent. Already-run migrations are skipped by the migration
table tracking. New migrations run once. No data is lost. No columns are duplicated.

### 2.6 — Clear and rebuild caches

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2.7 — Restart workers (required after every code deploy)

```bash
# Signal running workers to finish current job and exit gracefully
php artisan queue:restart

# Wait 5–10 seconds, then restart via supervisor
sleep 8
sudo supervisorctl restart levelup-worker:*

# Confirm workers came back up
sudo supervisorctl status levelup-worker:*
```

The queue:restart step is critical — without it, old workers continue running
stale code in memory even after files are updated.

### 2.8 — Smoke test

```bash
# 1. Health check
curl -s https://app.levelupgrowth.io/api/health | python3 -m json.tool

# 2. Ping
curl -s https://app.levelupgrowth.io/api/ping

# 3. Public plans (no auth — verifies routing + DB)
curl -s https://app.levelupgrowth.io/api/public/plans | python3 -m json.tool
```

Expected health response:
```json
{
  "status": "healthy",
  "checks": {
    "database": "ok",
    "redis": "ok",
    "queue": "ok",
    "queue_depth": 0
  }
}
```

If `database` or `redis` is not `"ok"`, stop and investigate before proceeding.

---

## PART 3 — NEW SERVER FROM BASELINE (fastest path for horizontal scale or disaster recovery)

Use this instead of migrate on a new server. The baseline.sql already contains the
full schema + seeded data from a known-good state. This is faster and safer than
running 50 migrations on a cold server.

### 3.1 — Upload baseline and restore

```bash
# On new server, after composer install and .env setup:
mysql \
  -h $DB_HOST \
  -P $DB_PORT \
  -u $DB_USERNAME \
  -p$DB_PASSWORD \
  $DB_DATABASE < /path/to/baseline_TIMESTAMP.sql

echo "Schema restored from baseline"
```

### 3.2 — Apply only new migrations (since baseline was taken)

```bash
php artisan migrate --force
```

This runs only the migrations that were not in the baseline snapshot. If the
baseline is current, this step completes with "Nothing to migrate."

---

## PART 4 — ROLLBACK

### Code rollback (keep DB, revert files)

```bash
# Restore previous release files
PREV=/var/www/releases/release_PREVIOUS_DATE/boss888-patch
rsync -av --exclude='.env' --exclude='vendor/' --exclude='storage/' $PREV/ /var/www/levelup/

# Clear then rebuild caches
php artisan config:clear && php artisan route:clear && php artisan cache:clear
php artisan config:cache && php artisan route:cache

# Restart workers
php artisan queue:restart && sleep 8 && sudo supervisorctl restart levelup-worker:*
```

### Full rollback (code + database)

```bash
# 1. Restore database from pre-deploy backup
mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE \
  < /var/www/backups/pre_deploy_TIMESTAMP.sql

# 2. Restore code
rsync -av --exclude='.env' --exclude='vendor/' $PREV/ /var/www/levelup/

# 3. Clear then rebuild caches and restart
php artisan config:clear && php artisan route:clear && php artisan cache:clear
php artisan config:cache && php artisan route:cache
php artisan queue:restart && sleep 8 && sudo supervisorctl restart levelup-worker:*
```

---

## PART 5 — MONITORING

### Queue health

```bash
# Via API (requires admin JWT)
curl -H "Authorization: Bearer ADMIN_JWT" \
  https://app.levelupgrowth.io/api/admin/queue

# Via artisan
php artisan queue:health-report

# Direct supervisor status
sudo supervisorctl status levelup-worker:*
```

### Queue backlog and failed jobs

Run these after every deploy and whenever the system feels slow:

```bash
# Queue backlog — count of jobs waiting to be processed
# If this grows continuously, workers are not processing fast enough
mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE \
  -e "SELECT queue, COUNT(*) as waiting FROM jobs GROUP BY queue;"

# Failed jobs — jobs that exhausted all retries
# Investigate any non-zero count before proceeding
mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE \
  -e "SELECT COUNT(*) as failed_jobs FROM failed_jobs; SELECT id, queue, LEFT(exception,200) as error, failed_at FROM failed_jobs ORDER BY failed_at DESC LIMIT 10;"

# Tasks stuck in queued/running state (application-level queue)
mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE \
  -e "SELECT status, COUNT(*) FROM tasks GROUP BY status;"
```

**Decision rule:**
- `failed_jobs > 0` → inspect errors before proceeding with any further deploys
- `tasks WHERE status='queued'` growing → workers not picking up tasks, check Redis connection and supervisor
- `tasks WHERE status='running' AND updated_at < NOW() - INTERVAL 10 MINUTE` → stale tasks, run `php artisan tasks:recover-stale`

### Redis connection check

```bash
# Confirm Redis is reachable and the queue connection works
php artisan tinker --execute="
  echo 'Cache: ' . (Cache::put('deploy_check', 'ok', 10) ? 'ok' : 'FAIL') . PHP_EOL;
  echo 'Queue connection: ' . config('queue.default') . PHP_EOL;
  echo 'Redis ping: ' . Redis::ping() . PHP_EOL;
"
```

### Stuck tasks

```bash
# Find tasks stuck in queued/running for >10 minutes
php artisan tasks:recover-stale

# Or via admin API
curl -X POST -H "Authorization: Bearer ADMIN_JWT" \
  https://app.levelupgrowth.io/api/admin/recover-stale
```

### Logs

```bash
# Laravel application log
tail -f /var/www/levelup/storage/logs/laravel.log

# Queue worker log
tail -f /var/www/levelup/storage/logs/worker.log

# Nginx error log
tail -f /var/log/nginx/levelup-app.error.log
```

---

## PART 6 — CRON (Laravel scheduler)

Add to www-data's crontab (`sudo crontab -u www-data -e`):

```cron
* * * * * php /var/www/levelup/artisan schedule:run >> /dev/null 2>&1
```

The scheduler handles:
- Sarah proactive daily check (08:00)
- Trial expiry check (06:00)
- Sarah weekly review (Monday 09:00)
- Sarah monthly strategy (1st of month 10:00)

---

## PART 7 — SUMMARY: WHAT NOT TO DO

| Command | Status | Reason |
|---------|--------|--------|
| `php artisan migrate:fresh` | ❌ NEVER on production | Drops all data |
| `php artisan migrate:fresh --seed` | ❌ NEVER on production | Drops all data |
| Deploy without backup | ❌ Never | No rollback path |
| Overwrite `.env` with rsync | ❌ Never | Wipes live credentials |
| Skip `queue:restart` after deploy | ❌ Never | Workers run stale code |
| `php artisan migrate --force` | ✅ Safe | Incremental, idempotent |
| `php artisan db:seed --force` | ⚠️ Only on fresh DB | Seeders are not idempotent |
