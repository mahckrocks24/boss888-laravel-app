# BOSS888 -- PHASE 3E-STAGE 0: STORAGE SIZING & BANDWIDTH ESTIMATE

**Date:** 2026-07-22 · **Basis:** measured on staging, not estimated from assumption
**Provider:** DigitalOcean Spaces, bucket `boss888-backups`, region `ams3` (unchanged -- no new provider)

---

## 1. MEASURED BASELINE (2026-07-22, post-implementation)

| Bucket prefix | Bytes | Objects | Nature |
|---|---:|---:|---|
| `files-mirror/levelup-media/` | 1,253,501,237 (1.17 GiB) | 1,382 | **NEW** -- additive media mirror |
| `files-archive/` | 7,352,000 (7.0 MiB) per daily set | 16/set | **NEW** -- 8 groups, rotated at 30 |
| `files-manifest/` | ~93 KiB per run + 3.2 KiB env-keys | 3/run | **NEW** -- rotated at 90 |
| `db-backups/` | 153,373,056 (146 MiB) | 30 | pre-existing, bounded |
| `code-snapshots/` | 4,358,127,159 (4.06 GiB) | 4 | pre-existing, **static, see §5** |
| `daily/` | 43,227,612 (41 MiB) | 12 | pre-existing |
| **Whole bucket** | **~5.83 GB** | ~1,495 | |

**Source of truth measured:** `storage/app` = 1,408 files / 1,257,672,272 bytes (1.17 GiB).
Mirror holds 1,382 objects; the 26-file difference is the deliberately excluded transient
scratch (`public/tmp/`, `studio-render-tmp/`, `studio-video-tmp/`). Reconciled to zero unexplained gaps.

---

## 2. MEASURED GROWTH RATE

Media creation, by age window (`storage/app`, all file types):

| Window | Files | Bytes | Rate |
|---|---:|---:|---:|
| Last 7 days | 40 | 54.6 MiB | **7.81 MiB/day** |
| Last 14 days | 84 | 102.8 MiB | **7.34 MiB/day** |
| Last 30 days | 172 | 194.3 MiB | **6.48 MiB/day** |
| Last 60 days | 895 | 466.7 MiB | **7.78 MiB/day** |

Four independent windows agree at **~7 MiB/day**, so the figure is stable rather than a spike.
(The 90-day window reads 13.3 MiB/day only because the entire dataset begins 2026-05-06 --
77 days of history in a 90-day window. It is not a real acceleration.)

**Planning rate: 7 MiB/day ≈ 213 MiB/month ≈ 2.5 GiB/year of new media.**

---

## 3. STORAGE PROJECTION

| Component | Behaviour | Now | +6 months | +12 months |
|---|---|---:|---:|---:|
| `files-mirror` (media) | grows ~7 MiB/day, additive | 1.17 GiB | 2.42 GiB | 3.67 GiB |
| `files-archive` | **bounded** 7.0 MiB × 30 | 0.21 GiB | 0.21 GiB | 0.21 GiB |
| `files-manifest` | **bounded** ~93 KiB × 90 | 0.01 GiB | 0.01 GiB | 0.01 GiB |
| `db-backups` | **bounded** 30 dumps | 0.15 GiB | 0.15 GiB | 0.15 GiB |
| `code-snapshots` + `daily` | static / pre-existing | 4.10 GiB | 4.10 GiB | 4.10 GiB |
| **Total** | | **5.64 GiB** | **6.89 GiB** | **8.14 GiB** |

**DO Spaces base plan: $5/month covers 250 GB storage + 1 TB transfer.**

At 12 months the whole bucket is **8.14 GiB = 3.3% of the included 250 GB**. Only the media mirror
grows unboundedly; at the measured rate it would take **~34 years** to reach the 250 GB included
allowance. **No storage cost increase is triggered by Stage 0.** Overage, if ever reached, is
$0.02/GB/month.

Archives are bounded because they are full-tree tarballs rotated at 30, and they are small
(7.0 MiB for all 8 groups) precisely because reproducible artefacts are excluded -- see §5.

---

## 4. BANDWIDTH ESTIMATE

**Uploads (ingress) to DigitalOcean Spaces are free and do not count against transfer.**
Only egress counts.

**Daily upload volume (ingress, free):**

| Item | Volume |
|---|---:|
| Archive set, all 8 groups (full each night) | 7.0 MiB |
| Media mirror delta (incremental) | ~7 MiB |
| Manifests + env-keys | ~0.1 MiB |
| **Total per night** | **~14 MiB** |
| **Per month** | **~420 MiB** |

**Egress (billable against the 1 TB allowance):**

| Activity | Frequency | Volume | Monthly |
|---|---|---:|---:|
| Rotating deep verify (1 archive/day, re-downloaded and re-hashed) | daily | ~0.9 MiB | ~27 MiB |
| Full `--verify` (all 8 archives + 5 sampled media objects) | weekly, Sun 04:00 | ~13.5 MiB | ~58 MiB |
| **Routine total** | | | **~85 MiB/month** |

**Routine egress is ~0.008% of the 1 TB included transfer.** Effectively free.

**Disaster-recovery egress (one-off):** a full restore pulls the media mirror (1.17 GiB today,
~3.7 GiB at 12 months) plus archives (7 MiB) -- **~1.2 GiB now, under 4 GiB within a year.**
Still ~0.4% of one month's included transfer. A full DR restore has no meaningful bandwidth cost.

**Local disk impact on the droplet:** staging dir `/var/backups/boss888-files` holds one archive
set (7.0 MiB) plus 7 days of local rotation ≈ **50 MiB peak**. Negligible against the 14 GB free.
The script refuses to run below 3 GB free.

---

## 5. THE TWO SIZING DECISIONS THAT MATTER

**(a) Media is mirrored, not tarred.** A nightly full tarball of `storage/app` would upload
1.2 GB/night and, at 30 days retention, occupy **~36 GB** to protect 1.17 GiB of actual data --
about 30x waste, growing. Incremental object sync uploads only the ~7 MiB/day that is genuinely
new, and the dated sha256 manifest supplies the point-in-time recovery that a plain mirror lacks.

**(b) Reproducible artefacts are excluded.** The first implementation run (v1.0.0) archived
`vendor/` and a 274 MB bundled Chrome under `.puppeteer-cache/`, producing a **347 MiB** archive
set. Excluding what `composer install`, `npm ci` and `puppeteer install` can rebuild cut the same
set to **7.0 MiB -- a 98% reduction** with no loss of recoverability. At 30 days retention that is
the difference between **10.2 GB and 0.21 GB** of bucket.

---

## 6. OBSERVATION FOR THE FOUNDER (not a Stage 0 action)

`code-snapshots/` holds **4.06 GiB across 4 objects -- 70% of the entire bucket**, and is larger
than everything Stage 0 added. It has no retention policy attached and predates this work. It is
not a Stage 0 deliverable and has not been touched. Recommend a decision on its retention at the
next review; reclaiming it would roughly halve total bucket usage.
