# BOSS888 -- PHASE 3E-STAGE 0: FILE RESTORE PROCEDURE

**Version:** 1.0 - 2026-07-22
**Applies to:** `backup-files.sh` v1.1.1 and the DigitalOcean Spaces bucket `boss888-backups` (region `ams3`)
**Companion documents:** `STAGE0-BACKUP-RUNBOOK.md` (operations), `STAGE0-VERIFICATION-REPORT-2026-07-22.md` (evidence), `RUNBOOK-RESTORE.md` (pre-existing database restore)

> **Read this first.** This procedure restores **files**. Databases are restored by the pre-existing
> `db-backups/` path. **Secrets are not in these backups by design** and must come from the encrypted
> archive vault. A restore that skips the vault step will produce a running application that cannot
> decrypt its own data. See section 0.

---

## 0. THE THREE THINGS THAT ARE NOT IN THE FILE BACKUP

| Missing by design | Where it actually lives | Consequence if skipped |
|---|---|---|
| **`.env` files / all secrets** | Encrypted archive vault (droplet + D:). Passphrase at `C:\Users\markr\Staging\keys\` on the home PC **only**. | App will not boot, or boots and cannot decrypt. |
| **`APP_KEY` specifically** | Same vault, inside `.env` | **Irreversible data loss.** Laravel-encrypted columns cannot be decrypted with a new `APP_KEY`. Restoring a database without its original `APP_KEY` is a silent, permanent loss. Recover the key **before** declaring a restore successful. |
| **Databases** | `s3://boss888-backups/db-backups/` (30-day retention) | No application data. |

Reproducible artefacts are also excluded and must be rebuilt, not restored:
`vendor/` (`composer install`), `node_modules/` (`npm ci`), `.puppeteer-cache/`
(`npx puppeteer browsers install chrome` -- 274 MB, needed for headless validation only).

---

## 1. WHAT IS WHERE

```
s3://boss888-backups/
  files-archive/<group>/<group>-YYYYMMDD-HHMMSS.tar.zst      point-in-time, 30 kept
                        <...>.tar.zst.sha256                 integrity hash
  files-mirror/levelup-media/...                             ADDITIVE object mirror of storage/app
  files-manifest/levelup-media/
        levelup-media-manifest-<stamp>.tsv.zst               path, size, mtime  (90 kept)
        levelup-media-sha256-<stamp>.tsv.zst                 path -> sha256     (90 kept)
  files-manifest/env-keys/env-keys-<stamp>.txt               variable NAMES only (90 kept)
  db-backups/                                                pre-existing DB dumps
```

**Groups and what they contain**

| Group | Restores to | Contents |
|---|---|---|
| `levelup-app` | `/var/www/levelup-staging` | Laravel app source, config, migrations, **`storage/templates` builder content** |
| `levelup-public` | `/var/www/levelup-staging/public` | Public web assets |
| `mr-src` | `/var/www/markraymundo-src` | **Only copy of the Astro source. Not in git.** |
| `mr-admin` | `/var/www/markraymundo-admin` | CMS/CRM app source |
| `mr-site` | `/var/www/markraymundo.com` | Built site output |
| `clutter-angels` | `/var/www/clutter-angels` | Site |
| `ptaa` | `/var/www/ptaa` | PTAA app source (co-tenanted client app) |
| `sysconfig` | `/` | `etc/nginx`, `etc/letsencrypt/renewal`, `etc/php`, `etc/supervisor`, `var/spool/cron/crontabs` |

`levelup-media` (the 1.2 GB of `storage/app`: `ai-images`, `template-images`, `sites`,
`builder-heroes`, `studio*`) is the **mirror**, not an archive -- restore it with `s3cmd sync`, below.

---

## 2. SCENARIO A -- Recover one deleted or corrupted media file

Fastest path. The mirror is additive, so **deleted files are still off-site**.

```bash
# 1. Find it (search the newest manifest rather than guessing the path)
s3cmd -c /root/.s3cfg ls s3://boss888-backups/files-manifest/levelup-media/ | tail -5
s3cmd -c /root/.s3cfg get --force \
  s3://boss888-backups/files-manifest/levelup-media/levelup-media-manifest-<stamp>.tsv.zst /tmp/m.tsv.zst
zstd -d /tmp/m.tsv.zst -o /tmp/m.tsv
grep 'the-filename' /tmp/m.tsv

# 2. Pull the object back to a SAFE location first -- never straight over live data
s3cmd -c /root/.s3cfg get \
  s3://boss888-backups/files-mirror/levelup-media/public/ai-images/2/<hash>.png /tmp/restore/

# 3. Verify it against the recorded hash before putting it back
grep '<hash>.png' <(zstd -dc /tmp/levelup-media-sha256-<stamp>.tsv.zst)
sha256sum /tmp/restore/<hash>.png

# 4. Only then copy into place and fix ownership
cp /tmp/restore/<hash>.png /var/www/levelup-staging/storage/app/public/ai-images/2/
chown www-data:www-data /var/www/levelup-staging/storage/app/public/ai-images/2/<hash>.png
```

---

## 3. SCENARIO B -- Recover the whole media tree

```bash
# Restore to a staging path first. NEVER sync straight onto the live tree.
mkdir -p /var/backups/media-restore
s3cmd -c /root/.s3cfg sync \
  s3://boss888-backups/files-mirror/levelup-media/ /var/backups/media-restore/

# Reconcile against the manifest before switching over
find /var/backups/media-restore -type f | wc -l     # compare with manifest line count

# Switch over
rsync -a /var/backups/media-restore/ /var/www/levelup-staging/storage/app/
chown -R www-data:www-data /var/www/levelup-staging/storage/app
php /var/www/levelup-staging/artisan storage:link    # if public/storage is missing
```

> **The mirror is additive.** It contains every file ever seen, including ones deliberately
> deleted in production. A blind full restore may resurrect deleted content. When exact
> point-in-time state matters, restore only the paths listed in the manifest for that date.

---

## 4. SCENARIO C -- Recover an application tree (any archive group)

```bash
GROUP=levelup-app          # or mr-src, ptaa, sysconfig, ...

# 1. Newest archive for that group
NEWEST=$(s3cmd -c /root/.s3cfg ls s3://boss888-backups/files-archive/$GROUP/ \
         | awk '{print $4}' | grep 'tar.zst$' | sort | tail -1)
echo "$NEWEST"

# 2. Download archive + hash, and VERIFY before trusting it
cd /var/backups && s3cmd -c /root/.s3cfg get --force "$NEWEST" .
s3cmd -c /root/.s3cfg get --force "$NEWEST.sha256" .
sha256sum -c "$(basename "$NEWEST").sha256"    # must print OK
zstd -t "$(basename "$NEWEST")"                 # must print OK

# 3. Extract to a staging dir and inspect -- never extract over live code
mkdir -p /var/backups/restore-$GROUP
tar -xf "$(basename "$NEWEST")" -C /var/backups/restore-$GROUP
find /var/backups/restore-$GROUP -maxdepth 2 | head

# 4. Move into place (archives restore with their directory prefix, e.g. levelup-staging/...)
rsync -a /var/backups/restore-$GROUP/levelup-staging/ /var/www/levelup-staging/
chown -R www-data:www-data /var/www/levelup-staging
```

**`sysconfig` restores relative to `/`** -- its members are `etc/nginx/...`, `var/spool/cron/crontabs/...`.
Extract to a staging dir and copy selected files deliberately. Do **not** `tar -xf ... -C /`.

---

## 5. SCENARIO D -- `markraymundo-src` is lost (highest-risk single item)

This tree is **not a git repository** and the local Windows copy is stale. The off-site archive is
the authoritative recovery source.

```bash
NEWEST=$(s3cmd -c /root/.s3cfg ls s3://boss888-backups/files-archive/mr-src/ \
         | awk '{print $4}' | grep 'tar.zst$' | sort | tail -1)
cd /var/backups && s3cmd -c /root/.s3cfg get --force "$NEWEST" .
tar -xf "$(basename "$NEWEST")" -C /var/backups/
cd /var/backups/markraymundo-src
npm ci            # node_modules is NOT backed up -- rebuild from package-lock.json
npm run build     # regenerates dist/
```

Expect **36 files under `src/`** (the 8 `*.bak-*` scratch files are deliberately excluded).

---

## 6. SCENARIO E -- Total droplet loss (bare-metal rebuild)

Strict order. Steps 3 and 6 are the ones people skip and regret.

1. **Provision** a new droplet. Restore the most recent DO snapshot if usable (`do-snapshot.sh`, 5 retained) -- that alone may make steps 2-4 unnecessary.
2. **Base stack:** nginx, PHP 8.3-FPM, MySQL, PostgreSQL, Redis, supervisor, certbot, s3cmd, zstd.
3. **Credentials first.** Recover `/root/.s3cfg` (Spaces keys) from the encrypted vault -- **without it you cannot reach any of these backups.** Recover every `.env` from the vault, `APP_KEY` above all.
4. **`sysconfig`** archive -> nginx vhosts, php config, supervisor, crontabs, letsencrypt renewal configs.
5. **Application archives** -> `levelup-app`, `levelup-public`, `mr-src`, `mr-admin`, `mr-site`, `clutter-angels`, `ptaa`.
6. **Rebuild dependencies:** `composer install --no-dev` in each Laravel root; `npm ci` in `markraymundo-src`. Optionally `npx puppeteer browsers install chrome`.
7. **Databases** from `s3://boss888-backups/db-backups/` (see `RUNBOOK-RESTORE.md`).
8. **Media** -- Scenario B.
9. `php artisan storage:link`, `php artisan config:clear` (**never `config:cache`** on this platform -- the runtime client reads `env()` directly and caching breaks it).
10. **TLS:** `certbot --nginx` to reissue. Private keys are deliberately not backed up.
11. **Verify:** `/root/backup-files.sh --verify` then application smoke tests.

**Realistic RTO** with snapshot: 1-2 h. Without snapshot, from files only: 4-6 h, dominated by
dependency rebuild and TLS reissue. **RPO: 24 h** (daily 01:00/01:30 cycle).

---

## 7. RESTORE VERIFICATION CHECKLIST

Do not declare a restore complete until every line is ticked.

- [ ] `sha256sum -c` passed for every archive used
- [ ] `zstd -t` passed for every archive used
- [ ] Media file count reconciles against the manifest for the target date
- [ ] `APP_KEY` is the **original** key from the vault, not a regenerated one
- [ ] Application boots; `/health` responds
- [ ] Encrypted DB columns decrypt correctly (proves `APP_KEY` is right)
- [ ] Builder templates render (`storage/templates`, 198 files)
- [ ] Customer sites serve over the wildcard host
- [ ] File ownership is `www-data:www-data` throughout
- [ ] `/root/backup-files.sh --verify` returns rc=0 on the rebuilt host
