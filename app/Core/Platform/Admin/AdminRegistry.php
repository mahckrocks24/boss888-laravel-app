<?php

namespace App\Core\Platform\Admin;

use Illuminate\Http\Request;

/**
 * The admin page registry, filtered by who is asking.
 *
 * Sidebar, titles, routes, the client-side slug map, breadcrumbs and search all
 * read `config/admin_pages.php`. Filtering here therefore filters all of them
 * at once — which is the point. A module hidden in one place and present in
 * another is not hidden; it is merely inconvenient to find, and the 2026-07-29
 * audit is a record of what happens when three lists that should agree do not.
 *
 * Nothing is sent to the browser and removed by JavaScript. If the server did
 * not approve it, the browser never receives it.
 */
final class AdminRegistry
{
    public function __construct(private readonly AdminAccess $access) {}

    public static function make(): self
    {
        return new self(new AdminAccess());
    }

    /**
     * The pages this request may know about, in registry order.
     *
     * An entry with no `capability` key is returned for any platform admin —
     * identical to today's behaviour for all 54 pre-existing pages.
     *
     * @return array<string,array<string,mixed>>
     */
    public function visibleFor(?Request $request): array
    {
        $visible = [];

        foreach ((array) config('admin_pages', []) as $key => $entry) {
            if ($this->access->allows($request, $entry['capability'] ?? null)) {
                $visible[$key] = $entry;
            }
        }

        return $visible;
    }

    /**
     * The registry key for a slug, or null when it is not visible.
     *
     * "Not visible" and "does not exist" return the same thing on purpose. The
     * caller cannot distinguish a hidden module from a typo, and so neither can
     * the person typing the URL.
     */
    public function keyForSlug(?Request $request, string $slug): ?string
    {
        foreach ($this->visibleFor($request) as $key => $entry) {
            if (($entry['slug'] ?? null) === $slug) { return $key; }
        }

        return null;
    }

    /**
     * key => slug, for the client-side navigator.
     *
     * This is what becomes `window.ADMIN_PAGES`. Unfiltered, it was the
     * concrete disclosure: an anonymous visitor received the slug of every
     * module on the platform, including private ones.
     *
     * @return array<string,string>
     */
    public function slugMapFor(?Request $request): array
    {
        $map = [];

        foreach ($this->visibleFor($request) as $key => $entry) {
            $map[$key] = $entry['slug'];
        }

        return $map;
    }

    /** Every page, unfiltered. For tests and for proving compatibility. */
    public static function all(): array
    {
        return (array) config('admin_pages', []);
    }
}
