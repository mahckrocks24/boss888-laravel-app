<?php

namespace App\Core\LaunchScope;

use Illuminate\Support\Facades\DB;

/**
 * W6 — the single authority for turning an agent slug into something a customer
 * may see.
 *
 * WHY THIS EXISTS
 * The `Agent` Eloquent model carries a global scope that hides removed agents,
 * but a number of customer-facing read paths use raw `DB::table('agents')`
 * queries, which bypass it entirely. A global scope that can be walked around is
 * not a control. Every customer-facing resolution now goes through here.
 *
 * THE TWO ANSWERS
 *   - A RETAINED agent resolves normally and is active + assignable.
 *   - A REMOVED agent resolves to a HISTORICAL representation. It is never
 *     active, never assignable, never clickable, and never described as
 *     temporarily unavailable. Historical records keep their authorship — a
 *     message Marcus wrote in May was still written by Marcus — but nothing
 *     presents him as someone you can use today.
 *
 * Removed agents are deliberately NOT deleted from the database. Deleting them
 * would destroy the authorship of real historical records.
 */
final class AgentDirectory
{
    /** Neutral grey. Removed agents never render in a live brand colour. */
    public const HISTORICAL_COLOR = '#6B7280';

    /** Suffix appended to a removed agent's name in any customer surface. */
    public const HISTORICAL_SUFFIX = ' — historical agent';

    /** Fallback when a slug resolves to nothing at all. */
    public const UNKNOWN = ['name' => 'LevelUp Growth', 'slug' => 'system', 'color' => '#6C5CE7'];

    /** @var array<string,array> per-request memo */
    private static array $cache = [];

    public static function isRemoved(?string $slug): bool
    {
        return LaunchScopePolicy::isRemovedAgent((string) $slug);
    }

    public static function isRetained(?string $slug): bool
    {
        $s = strtolower(trim((string) $slug));
        return $s !== '' && !self::isRemoved($s);
    }

    /**
     * Resolve a slug for a customer-facing badge / author label.
     *
     * @return array{name:string,slug:string,color:string,historical:bool,active:bool,assignable:bool}
     */
    public static function resolve(?string $slug): array
    {
        $s = strtolower(trim((string) $slug));
        if ($s === '') {
            return self::pack(self::UNKNOWN['name'], self::UNKNOWN['slug'], self::UNKNOWN['color'], false);
        }
        if (isset(self::$cache[$s])) return self::$cache[$s];

        $row = DB::table('agents')->where('slug', $s)->first(['name', 'slug', 'color']);
        $name = $row->name ?? ucfirst($s);

        if (self::isRemoved($s)) {
            // Historical only. Neutral colour, explicit label, not assignable.
            return self::$cache[$s] = self::pack(
                $name . self::HISTORICAL_SUFFIX, $s, self::HISTORICAL_COLOR, true
            );
        }

        return self::$cache[$s] = self::pack(
            $name, $row->slug ?? $s, ($row->color ?? null) ?: '#6C5CE7', false
        );
    }

    /**
     * Resolve a free-text author/sender string (agent_messages.sender holds a
     * display NAME such as "Marcus", not a slug).
     */
    public static function resolveSender(?string $sender): string
    {
        $raw = trim((string) $sender);
        if ($raw === '') return self::UNKNOWN['name'];

        $slug = strtolower($raw);
        if (self::isRemoved($slug)) {
            return $raw . self::HISTORICAL_SUFFIX;
        }
        return $raw;
    }

    /** Slugs a customer may currently be assigned to / mention / select. */
    public static function retainedSlugs(): array
    {
        $all = DB::table('agents')->pluck('slug')->all();
        return array_values(array_filter($all, fn($s) => !self::isRemoved($s)));
    }

    /**
     * True when a task row should be treated as legacy work rather than
     * current, actionable work. Used to keep historical removed-agent and
     * removed-engine tasks out of live approval/execution queues.
     */
    public static function isLegacyTask(?string $engine, ?string $action, $assignedAgentsJson): bool
    {
        if (LaunchScopePolicy::isRemoved((string) $engine, (string) $action)) return true;

        $agents = $assignedAgentsJson;
        if (is_string($agents)) $agents = json_decode($agents, true);
        if (is_array($agents)) {
            foreach ($agents as $a) {
                if (self::isRemoved(is_string($a) ? $a : '')) return true;
            }
        }
        return false;
    }

    private static function pack(string $name, string $slug, string $color, bool $historical): array
    {
        return [
            'name'       => $name,
            'slug'       => $slug,
            'color'      => $color,
            'historical' => $historical,
            'active'     => !$historical,
            'assignable' => !$historical,
        ];
    }
}
