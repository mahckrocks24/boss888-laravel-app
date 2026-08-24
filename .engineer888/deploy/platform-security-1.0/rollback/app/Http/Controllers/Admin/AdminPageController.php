<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;

/**
 * Serves the admin console as one page per menu item.
 *
 * Before this, routes/web.php had a catchall — `/admin/{any?}` where `.*` —
 * that returned the same 359KB view for every URL, and the view booted a
 * hardcoded dashboard. The URL carried no meaning: /admin/users returned 200
 * and showed the dashboard, and so did /admin/nonsense.
 *
 * Now every menu item resolves through config/admin_pages.php, which is the
 * single source of truth for the sidebar, the page title and the routes. An
 * unknown slug 404s instead of silently rendering something else.
 *
 * NOTE: this app has no base Controller class; controllers here extend nothing.
 */
class AdminPageController
{
    /**
     * Assets are versioned by modification time rather than a hand-maintained
     * constant. staging.levelupgrowth.io sits behind Cloudflare, which holds
     * assets for 4h — a version someone forgets to bump means admins run stale
     * JavaScript for half a day. mtime cannot be forgotten.
     */
    private function assetVersion(): int
    {
        $newest = 0;
        foreach (['css/admin.css', 'js/admin-core.js', 'js/admin-shared.js', 'js/admin-bella.js'] as $asset) {
            $path = public_path($asset);
            if (is_file($path)) {
                $newest = max($newest, (int) filemtime($path));
            }
        }

        return $newest;
    }

    public function show(Request $request, ?string $slug = null)
    {
        $pages = config('admin_pages', []);
        $slug  = trim((string) $slug, '/');

        if ($slug === '') {
            return redirect('/admin/dashboard');
        }

        $key = null;
        foreach ($pages as $candidate => $entry) {
            if (($entry['slug'] ?? null) === $slug) {
                $key = $candidate;
                break;
            }
        }

        if ($key === null) {
            abort(404);
        }

        // Each page's renderer is its own view, mirroring the slug:
        //   ads/status -> resources/views/admin/pages/ads/status.blade.php
        //
        // A registry entry whose view is missing is a 500 waiting to happen, so
        // it is caught here and named. The old model's failure mode for this was
        // to render nothing at all and say nothing about it.
        $viewPath = 'admin.pages.' . str_replace('/', '.', $slug);
        if (! view()->exists($viewPath)) {
            abort(500, "Admin page '{$key}' is registered but its view {$viewPath} does not exist.");
        }

        // key => slug, so nav('someKey') can resolve a URL on the client.
        $slugMap = [];
        foreach ($pages as $candidate => $entry) {
            $slugMap[$candidate] = $entry['slug'];
        }

        return response()
            ->view('admin.shell', [
                'pages'    => $pages,
                'key'      => $key,
                'page'     => $pages[$key],
                'slugMap'  => $slugMap,
                'viewPath' => $viewPath,
                'v'        => $this->assetVersion(),
            ])
            // The shell holds no data — every figure on it is fetched from the
            // JSON API with a bearer token. Still not cacheable: it names the
            // signed-in admin's page and must not be held by a shared cache.
            ->header('Cache-Control', 'no-cache, private');
    }
}
