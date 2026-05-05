# LevelUp Growth Platform — Production Deployment Runbook
# Target: Digital Ocean Frankfurt Droplet
# Date: 2026-04-05 (D12 / M1)

## Prerequisites
- Digital Ocean account with a $48/mo Basic Droplet (4GB RAM, 2 vCPU, 80GB SSD) in Frankfurt (FRA1)
- Managed MySQL (DO) — $15/mo, 1GB RAM
- Railway Redis already running (levelup-runtime2-production.up.railway.app)
- Domain: levelupgrowth.io (DNS managed in Cloudflare or DO DNS)

---

## Phase 1 — Droplet Setup (one-time)

```bash
# 1. Create Droplet: Ubuntu 24.04 LTS, Frankfurt, Basic $48/mo
# 2. SSH in as root

# Install PHP 8.3 + extensions
apt update && apt upgrade -y
add-apt-repository ppa:ondrej/php -y
apt install -y php8.3 php8.3-fpm php8.3-mysql php8.3-redis \
    php8.3-curl php8.3-xml php8.3-mbstring php8.3-zip php8.3-gd \
    php8.3-bcmath php8.3-intl php8.3-cli

# Install nginx, composer, node
apt install -y nginx certbot python3-certbot-nginx supervisor
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs

# Create app user and directory
useradd -m -s /bin/bash levelup
mkdir -p /var/www/levelup
chown levelup:www-data /var/www/levelup
chmod 2775 /var/www/levelup

# Configure PHP-FPM pool
# Edit /etc/php/8.3/fpm/pool.d/www.conf:
#   user = www-data
#   pm = dynamic
#   pm.max_children = 20
#   pm.start_servers = 5
#   pm.min_spare_servers = 3
#   pm.max_spare_servers = 10
#   pm.max_requests = 500

# Increase PHP limits for file uploads
echo "upload_max_filesize = 20M" >> /etc/php/8.3/fpm/conf.d/99-levelup.ini
echo "post_max_size = 25M" >> /etc/php/8.3/fpm/conf.d/99-levelup.ini
echo "memory_limit = 256M" >> /etc/php/8.3/fpm/conf.d/99-levelup.ini
echo "max_execution_time = 300" >> /etc/php/8.3/fpm/conf.d/99-levelup.ini

systemctl restart php8.3-fpm
```

---

## Phase 2 — DNS Configuration

In Cloudflare (or DO DNS), add:
```
A   levelupgrowth.io        → [DROPLET_IP]  (proxy: OFF for initial cert)
A   www.levelupgrowth.io    → [DROPLET_IP]
A   app.levelupgrowth.io    → [DROPLET_IP]
A   admin.levelupgrowth.io  → [DROPLET_IP]
CNAME  api.levelupgrowth.io → app.levelupgrowth.io
```
Wait ~60 seconds for propagation, then issue certs.

---

## Phase 3 — SSL Certificates

```bash
# Issue wildcard cert (covers all subdomains)
certbot --nginx -d levelupgrowth.io -d www.levelupgrowth.io \
    -d app.levelupgrowth.io -d admin.levelupgrowth.io
# Follow prompts — auto-renewal set up automatically

# Deploy nginx config
cp /var/www/levelup/config/deploy/levelup-nginx.conf /etc/nginx/sites-available/levelup
ln -s /etc/nginx/sites-available/levelup /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

---

## Phase 4 — Application Deployment

```bash
# Upload the D12 bundle to /var/www/levelup
# (via rsync, scp, or GitHub Actions)
cd /var/www/levelup

# Install PHP dependencies
sudo -u www-data composer install --no-dev --optimize-autoloader

# Install Node dependencies + build React frontend
npm install
npm run build

# Set up environment
cp .env.example .env
# Edit .env — fill ALL values (DB, Redis, JWT_SECRET, Stripe, AI keys)
nano .env

# Generate app key
php artisan key:generate

# Set storage permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## Phase 5 — Database Setup

```bash
# Run migrations
php artisan migrate --force

# Seed essential data (plans, agents, intelligence)
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=AgentSeeder --force
php artisan intelligence:seed
php artisan intelligence:audit

# Create first platform admin
php artisan tinker --execute="
\$user = App\Models\User::create([
    'name' => 'Shukran',
    'email' => 'admin@levelupgrowth.io',
    'password' => Hash::make('[STRONG_PASSWORD]'),
    'is_platform_admin' => true,
]);
echo 'Admin created: ' . \$user->id . PHP_EOL;
"
```

---

## Phase 6 — Supervisor + Queue Workers

```bash
# Deploy supervisor config
cp /var/www/levelup/config/deploy/levelup-worker.conf /etc/supervisor/conf.d/
supervisorctl reread
supervisorctl update
supervisorctl start levelup-worker:*
supervisorctl start levelup-scheduler

# Verify workers running
supervisorctl status
# Expected:
#   levelup-scheduler                RUNNING   pid XXXX, uptime 0:00:05
#   levelup-worker:levelup-worker_00 RUNNING   pid XXXX, uptime 0:00:05
#   levelup-worker:levelup-worker_01 RUNNING   pid XXXX, uptime 0:00:05
#   ...
```

---

## Phase 7 — Stripe Webhook Registration

In Stripe Dashboard → Developers → Webhooks → Add endpoint:
```
URL: https://app.levelupgrowth.io/api/webhook/stripe
Events:
  - checkout.session.completed
  - invoice.paid
  - invoice.payment_failed
  - customer.subscription.deleted
  - customer.subscription.updated
```
Copy the webhook signing secret → add to `.env` as `STRIPE_WEBHOOK_SECRET=whsec_...`

---

## Phase 8 — Post-Deployment Smoke Tests

```bash
# 1. Health check
curl https://app.levelupgrowth.io/api/health
# Expected: {"status":"healthy","checks":{"database":"ok","redis":"ok",...}}

# 2. Ping
curl https://app.levelupgrowth.io/api/ping
# Expected: {"pong":true,"ts":...}

# 3. Plans API (public)
curl https://app.levelupgrowth.io/api/public/plans
# Expected: {"plans":[{"name":"Free",...},{"name":"Starter",...},...]}

# 4. Admin login
curl -X POST https://app.levelupgrowth.io/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@levelupgrowth.io","password":"[PASSWORD]"}'
# Expected: {"access_token":"lu_...","user":{"is_platform_admin":true,...}}

# 5. Marketing site
curl -I https://levelupgrowth.io
# Expected: HTTP/2 200

# 6. React SPA
curl -I https://app.levelupgrowth.io/app
# Expected: HTTP/2 200, Content-Type: text/html

# 7. Admin panel
curl -I https://admin.levelupgrowth.io/admin
# Expected: HTTP/2 200
```

---

## Phase 9 — Monitoring Setup

```bash
# UptimeRobot monitors (free tier, 5-min interval):
#   https://app.levelupgrowth.io/api/health
#   https://app.levelupgrowth.io/api/ping
#   https://levelupgrowth.io

# Log monitoring
tail -f /var/www/levelup/storage/logs/laravel.log
tail -f /var/www/levelup/storage/logs/worker.log
tail -f /var/log/nginx/levelup-app.error.log

# Queue monitoring
php artisan queue:monitor redis:tasks,redis:tasks-high

# Intelligence audit (weekly)
php artisan intelligence:audit
```

---

## Rollback Procedure

If deployment fails:
```bash
# Option 1: Re-deploy from previous D10 bundle
# Option 2: Roll back migrations (only if schema-breaking)
php artisan migrate:rollback --step=1

# Fallback: The Apr-3 WP stack (staging1.shukranuae.com) remains untouched
# DNS can be pointed back to WP in < 5 minutes via Cloudflare
```

---

## Deployment Checklist (pre-launch gate)

- [ ] All smoke tests passing
- [ ] `APP_DEBUG=false` in `.env`
- [ ] `BOSS888_DEBUG_ENABLED=false` in `.env`
- [ ] Stripe webhooks registered and tested
- [ ] Stripe live keys configured (not test keys)
- [ ] Admin account created (`is_platform_admin=true`)
- [ ] Plans seeded correctly (6 plans)
- [ ] Agents seeded (21 agents, sarah `is_dmm=true`)
- [ ] Intelligence seeded (11 engines)
- [ ] Queue workers running (5 workers + scheduler)
- [ ] SSL certificates valid (check expiry)
- [ ] Nginx rate limiting verified (test with wrk or hey)
- [ ] Backups configured (DO managed DB auto-backup enabled)
- [ ] UptimeRobot monitors active
- [ ] CORS tested from app.levelupgrowth.io and APP888 simulator
- [ ] Trial system tested (create website → check 50 credits deposited)
- [ ] Marketing site accessible and all 6 pages rendering
- [ ] Admin panel accessible at admin.levelupgrowth.io/admin
