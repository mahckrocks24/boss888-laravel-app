# BOSS888 — Disaster Recovery Runbook

**Last updated:** 2026-05-08 (Patch 6)
**Target RTO:** 2 hours from droplet destroy to platform live
**Source of truth:** this file + `BOSS888-STATE.md` + `boss888-audit/PLAN.md`

This runbook exists because the May 4, 2026 droplet destroy made it
clear that ad-hoc recovery is not durable. Every step below has been
walked through at least once.

---

## If the Droplet Dies

### Path A — Restore from DigitalOcean snapshot (fastest, 5–15 minutes)

If a recent `boss888-auto-*` snapshot exists in DO → Backups & Snapshots:

1. DigitalOcean → Backups & Snapshots
2. Find latest `boss888-auto-YYYYMMDD-HHMM` snapshot
3. **Create Droplet from Snapshot** (same region, same size or bigger)
4. Update Cloudflare A record to the new droplet IP
5. SSH in and verify `systemctl status nginx mysql php8.3-fpm redis-server cron`
6. Skip to **Step 8 (Verify)** below

### Path B — Rebuild from scratch (≈85 minutes)

If no usable snapshot exists.

#### Step 1 — Create new droplet (15 minutes)

1. DigitalOcean → Create → Droplet
2. **Ubuntu 22.04 (LTS)**, AMS3 region, **at least $12/mo** (2GB RAM minimum)
3. Add SSH key: paste `C:\Users\User\.ssh\do_levelup.pub` (or generate fresh)
4. Note the new IP

#### Step 2 — Install dependencies (20 minutes)

```bash
ssh -i C:\Users\User\.ssh\do_levelup root@NEW_IP

apt-get update && apt-get install -y \
  nginx php8.3-fpm php8.3-mysql php8.3-redis \
  php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml \
  php8.3-zip mysql-server redis-server certbot \
  python3-certbot-nginx git composer s3cmd cron
```

Start services:

```bash
systemctl enable --now nginx php8.3-fpm mysql redis-server cron
```

#### Step 3 — Clone repo (5 minutes)

```bash
mkdir -p /var/www
cd /var/www

# Use the github-app deploy key (per memory: reference_boss888_github_repos.md)
git clone git@github-app:mahckrocks24/boss888-laravel-app.git levelup-staging
cd levelup-staging
chown -R www-data:www-data .

sudo -u www-data composer install --no-dev --optimize-autoloader
```

#### Step 4 — Restore `.env` (2 minutes)

`.env` is **NOT** in git (gitignored for security). Sources:

- **Best:** local backup at `C:\Users\User\BOSS888-BACKUPS\` (env-backup-YYYYMMDD.txt)
- **Fallback:** rebuild from `.env.example` plus secrets from password manager
- **Last resort:** the latest `code-snapshot-*.tar.gz` contains `.env` if it was made before the destroy

```bash
cp /path/to/env-backup/.env /var/www/levelup-staging/.env
chown www-data:www-data /var/www/levelup-staging/.env
chmod 600 /var/www/levelup-staging/.env
```

Critical secrets the `.env` must contain:

- `APP_KEY` (generate with `php artisan key:generate` if missing — but this invalidates encrypted columns)
- `DB_PASSWORD` (the DB user password — see Step 5)
- `JWT_SECRET` (regenerating breaks all live JWTs; users must re-login)
- `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET`
- `OPENAI_API_KEY`, `DEEPSEEK_API_KEY`
- `RUNTIME_URL`, `RUNTIME_SECRET` (Railway runtime auth)
- `POSTMARK_TOKEN`
- `BELLA_ADMIN_TOKEN`
- `DATAFORSEO_USER` / `DATAFORSEO_PASS`

#### Step 5 — Restore DB (10 minutes)

```bash
DB_PASS='LvlUp2026!Staging#Secure'  # match .env DB_PASSWORD

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS levelup_staging
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'levelup'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL ON levelup_staging.* TO 'levelup'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

Restore from latest backup (offsite preferred, local fallback):

```bash
# Offsite (DO Spaces) — preferred
s3cmd get s3://boss888-backups/db/db-LATEST.sql.gz /tmp/db-restore.sql.gz

# OR local fallback (only useful if same droplet survived)
cp /root/backups/db-LATEST.sql.gz /tmp/db-restore.sql.gz

gunzip -c /tmp/db-restore.sql.gz | mysql -u levelup -p"${DB_PASS}" levelup_staging
```

Apply any pending migrations:

```bash
cd /var/www/levelup-staging
sudo -u www-data php artisan migrate --force
```

**NEVER `migrate:fresh` — that's the rule that prevented worse damage on May 4.**

#### Step 6 — Restore nginx + TLS (15 minutes)

Copy nginx config from git history (was committed in Patch 3 docs but the file itself
lives at `/etc/nginx/sites-available/levelup-staging`):

```bash
# Recreate the staging vhost
cat > /etc/nginx/sites-available/levelup-staging <<'NGINX'
# (paste contents from Patch 3 daily-progress entry)
NGINX

ln -sf /etc/nginx/sites-available/levelup-staging /etc/nginx/sites-enabled/levelup-staging

# Issue cert
systemctl stop nginx
certbot certonly --standalone -d staging.levelupgrowth.io \
  --non-interactive --agree-tos \
  --email admin@levelupgrowth.io --no-eff-email
systemctl start nginx
nginx -t && systemctl reload nginx
```

Set fileperms:

```bash
chown -R www-data:www-data /var/www/levelup-staging
chmod -R 775 /var/www/levelup-staging/storage /var/www/levelup-staging/bootstrap/cache
```

#### Step 7 — Restore crontabs (5 minutes)

```bash
# www-data scheduler
echo "* * * * * cd /var/www/levelup-staging && php artisan schedule:run >> /var/log/laravel-scheduler.log 2>&1" \
  | crontab -u www-data -

# root: backups + cert renewal + snapshot (snapshot needs DO_API_TOKEN)
crontab -u root - <<'CRON'
0 1 * * * /root/daily-backup.sh >> /var/log/daily-backup.log 2>&1
0 2 * * * DO_API_TOKEN=$(cat /root/.do-token 2>/dev/null) /root/do-snapshot.sh
0 3 * * * certbot renew --quiet --post-hook "systemctl reload nginx"
CRON

# Restore the daily-backup + do-snapshot scripts from git
# (in /var/www/levelup-staging — committed in Patch 6)
cp /var/www/levelup-staging/scripts/daily-backup.sh /root/daily-backup.sh
cp /var/www/levelup-staging/scripts/do-snapshot.sh /root/do-snapshot.sh
chmod +x /root/daily-backup.sh /root/do-snapshot.sh

mkdir -p /root/backups
touch /var/log/daily-backup.log /var/log/do-snapshot.log
```

#### Step 8 — Verify (10 minutes)

```bash
# Health checks
curl -k https://staging.levelupgrowth.io/api/system/health
# expect: HTTP 200 with {"status":"ok",...}

cd /var/www/levelup-staging
sudo -u www-data php artisan migrate:status | tail -10
# expect: all migrations Ran, no Pending

sudo -u www-data php artisan schedule:list | head -12
# expect: 8 commands listed

# Cron firing?
tail -5 /var/log/laravel-scheduler.log

# Nginx serving HTTPS?
curl -kI https://staging.levelupgrowth.io/api/system/health | head -5
# expect: HTTP/1.1 200, Strict-Transport-Security header present
```

#### Step 9 — Update DNS (5 minutes)

Cloudflare → `levelupgrowth.io` zone → DNS:

- Update A record for `staging` to new droplet IP
- Confirm SSL/TLS mode is **Full** (not Flexible, not Full strict — yet)
- Purge cache: Cache → Configuration → Purge Everything

**Total Path B time: ≈85 minutes.**

---

## Critical Files NOT in Git

| Path | What it is | Where to recover from |
|---|---|---|
| `/var/www/levelup-staging/.env` | App secrets | Local password manager + `.env.example` |
| `/etc/nginx/sites-available/levelup-staging` | nginx vhost | RUNBOOK Step 6 + Patch 3 daily-progress |
| `/etc/letsencrypt/live/staging.levelupgrowth.io/` | TLS cert + key | Re-issue via certbot (Step 6) |
| `/var/www/levelup-staging/storage/app/public/` | Generated media, hero JPGs, thumbnails, generated images | DO snapshot OR offsite media bucket (TODO) |
| `/var/www/levelup-staging/storage/templates/` | 17 industry templates | Code snapshot tarball (NOT in git) |
| `/var/www/levelup-staging/storage/creative-templates/` | 3 restaurant social posters | Same — code snapshot |
| `/root/backups/db-*.sql.gz` | Local DB backups (7-day rotation) | DO Spaces (offsite) |

---

## Where Backups Live

| Layer | Location | Retention | Purpose |
|---|---|---|---|
| **Local DB dumps** | `/root/backups/db-*.sql.gz` | 7 days (auto-rotated) | Same-droplet recovery |
| **Offsite DB dumps** | `s3://boss888-backups/db/` (DO Spaces) | 30 days target | Cross-region disaster |
| **DO snapshots** | DigitalOcean → Backups & Snapshots | 7 days target | Whole-droplet rebuild ≈ 5min |
| **Code** | `git@github-app:mahckrocks24/boss888-laravel-app.git` | infinite | App source |
| **Code archive** | `git@github-recovery:mahckrocks24/boss888-laravel.git` | frozen | Pre-DR baseline |
| **Local code tarball** | `/root/code-snapshot-*.tar.gz` | manual | Whole-tree snapshot incl. nginx |

---

## Pre-DR Checklist (run quarterly)

- [ ] Last successful local DB backup is < 24h old: `ls -la /root/backups/`
- [ ] Last successful offsite push is < 24h old: `tail /var/log/daily-backup.log`
- [ ] Last DO snapshot is < 7 days old: DO dashboard → Backups & Snapshots
- [ ] `.env` securely backed up to password manager + offline storage
- [ ] DNS provider account has 2FA + backup access
- [ ] DigitalOcean account has multiple payment methods + billing alerts
- [ ] Cloudflare account has 2FA + backup access
- [ ] Sentry receiving production errors (not silent)
- [ ] UptimeRobot is alive and probing (not paused)

---

## Key Credentials (store in password manager — NEVER in git)

- **DB:** `levelup` / `LvlUp2026!Staging#Secure` / `levelup_staging`
- **SSH:** `C:\Users\User\.ssh\do_levelup` (key) — host `134.209.93.41` AMS3 droplet
- **GitHub deploy keys:** `github-app` (live repo) + `github-recovery` (archive)
- **Admin user:** `admin@levelupgrowth.io` / user_id=1 / workspace_id=1
- **BELLA_ADMIN_TOKEN:** in `.env` (god-mode admin entry — rotate quarterly)

---

## Lessons from May 4

The Frankfurt droplet was destroyed by a DigitalOcean account-level
billing failure — payment method declined, account suspended, droplet
purged. Recovery took 2 days because:

1. No offsite backups (everything was on the destroyed droplet).
2. No documented restore procedure.
3. `.env` was rebuilt by hand from memory + audit logs.
4. nginx config was reverse-engineered from working tenant traffic.
5. 10 industry templates were permanently lost.

This runbook + Patch 6 backup automation prevent every one of these.
The acid test: schedule a "destroy and rebuild" drill once a quarter
on a throwaway droplet. If the drill takes more than 2 hours, the
runbook is wrong.
