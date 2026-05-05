# ROUTES-PATCH.md

The only change this patch requires to any PHP file on the server.

**File:** `/var/www/levelup-staging/routes/web.php`
**Lines:** 31–34 (the `/app/{any?}` route block)

---

## Why

The existing `/app/{any?}` route returns `view('app')` — which renders `resources/views/app.blade.php`, a React preloader shell that loads a Vite bundle from `public/app-react/assets/`. That React shell is empty (audit confirmed — only a spinner, no engine views).

This patch puts the real WP-admin dashboard at `public/app/app.html` as static files. We just need Laravel to serve that file instead of the React view at the `/app/` route.

---

## The change

**Before** (lines 31–34 of current `routes/web.php`):

```php
// ── SaaS App (React SPA) ──────────────────────────────────────────────────────
// Catch-all serves the React app. React Router handles all sub-routes.
Route::get('/app/{any?}', function () {
    return view('app');
})->where('any', '.*')->name('app');
```

**After:**

```php
// ── SaaS App (WP-Admin dashboard, rendered static) ────────────────────────────
// Serves the WordPress-derived dashboard as static HTML from public/app/
// (the 6 JS files next to it load normally via the <script> tags inside app.html).
Route::get('/app/{any?}', function () {
    return response()->file(public_path('app/app.html'));
})->where('any', '.*')->name('app');
```

**Only one line actually changes:**

```diff
-    return view('app');
+    return response()->file(public_path('app/app.html'));
```

Comment above is optional cosmetic update.

---

## How to apply

Option A — SSH with nano:

```bash
cd /var/www/levelup-staging
nano routes/web.php
# Go to line 32 with Ctrl+_ then type 32 Enter
# Change the line, Ctrl+O to save, Ctrl+X to exit
```

Option B — One-liner sed (safer, no editor):

```bash
cd /var/www/levelup-staging
sed -i.bak "s|return view('app');|return response()->file(public_path('app/app.html'));|" routes/web.php
```

The `-i.bak` writes a backup at `routes/web.php.bak` so you can revert with:

```bash
mv routes/web.php.bak routes/web.php
```

---

## No cache clear needed

Laravel picks up `routes/web.php` changes on the next request in non-production environments. In production (`APP_ENV=production`), run:

```bash
php artisan route:clear
php artisan route:cache
```

Staging (`APP_ENV=staging`) — just make the change and hit the URL.

---

## Verify

```bash
curl -sI https://staging.levelupgrowth.io/app/ | head -5
```

Expected: `HTTP/2 200` and `content-type: text/html`. First line of the body should be `<!DOCTYPE html>` not a React preloader.

Visually: open in Chrome. You should see the dark dashboard with the purple sidebar, not a spinner.

---

## Rollback

Replace the one line back:

```php
return view('app');
```

Or if you used the sed command:

```bash
mv routes/web.php.bak routes/web.php
```

That's it. The React shell is still on disk at `public/app-react/` — rollback is clean.
