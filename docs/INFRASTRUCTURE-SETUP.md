# Boss888 Phase 5 — Infrastructure Setup

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Redis 7+
- Supervisor (for queue workers)
- Composer

## Quick Setup

```bash
# 1. Start infrastructure (Docker)
docker compose -f docker/docker-compose.yml up -d

# 2. Configure environment
cp .env.example .env
# Set these values:
#   DB_CONNECTION=mysql
#   DB_HOST=127.0.0.1
#   DB_DATABASE=boss888
#   CACHE_DRIVER=redis
#   QUEUE_CONNECTION=redis
#   REDIS_HOST=127.0.0.1

# 3. Install + migrate + seed
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed

# 4. Start workers (3 minimum for concurrency testing)
php artisan queue:work redis --queue=tasks-high,tasks,tasks-low --sleep=1 --tries=4 &
php artisan queue:work redis --queue=tasks-high,tasks,tasks-low --sleep=1 --tries=4 &
php artisan queue:work redis --queue=tasks-high,tasks,tasks-low --sleep=1 --tries=4 &

# Or use Supervisor (recommended):
sudo cp config/supervisor/boss888-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start boss888-worker:*
```

## Supervisor Config

See `config/supervisor/boss888-worker.conf`:
- 5 worker processes
- Priority queue order: tasks-high → tasks → tasks-low → default
- 4 retries with exponential backoff
- Auto-restart on crash

## Verify Infrastructure

```bash
# Check Redis
redis-cli ping  # → PONG

# Check MySQL
php artisan tinker --execute="DB::select('SELECT 1')"

# Check workers
php artisan queue:monitor tasks-high,tasks,tasks-low

# Check system health
curl http://localhost:8000/api/system/health | jq
```

## Running Phase 5 Tests

```bash
# Full load test (500 tasks, 5 workspaces)
php artisan boss888:load-test --tasks=500 --workspaces=5

# Idempotency stress test (concurrent duplicates)
php artisan boss888:idempotency-test --concurrent=10

# Connector validation (requires staging WordPress)
php artisan boss888:connector-test --connector=wordpress

# Circuit breaker validation
php artisan boss888:circuit-test --connector=wordpress --failures=10

# Worker failure recovery
php artisan boss888:worker-failure-test

# Rate limit validation
php artisan boss888:rate-limit-test --requests=100

# Full infrastructure report
php artisan boss888:infra-report --json
```

## Performance Metrics Collection

All test commands write metrics to the `task_events` table and Redis.
The infra-report command aggregates and outputs results.

## Critical Rules

- NEVER use `QUEUE_CONNECTION=sync` during Phase 5
- NEVER use `CACHE_DRIVER=array` during Phase 5
- NEVER use `DB_CONNECTION=sqlite` during Phase 5
- Workers MUST be running in parallel (minimum 3)
