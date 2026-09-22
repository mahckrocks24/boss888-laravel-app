<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A business profile inside a workspace (RFC-0011 U1). One row per workspace is the default; the workspaces.*
 * profile columns mirror it (see mirrorToWorkspace) so unrewired readers keep seeing today's values.
 */
class Business extends Model
{
    use SoftDeletes;

    /** The profile fields shared with workspaces.* (the mirror): workspaces column => businesses column. */
    public const MIRROR = ['business_name' => 'name', 'industry' => 'industry', 'services_json' => 'services_json', 'goal' => 'goal', 'location' => 'location', 'brand_color' => 'brand_color', 'logo_url' => 'logo_url'];

    /** Memory keys that are profile facts (portfolio-level today; biz:{id}:key per business). */
    public const MEMORY_FACTS = ['business_name', 'industry', 'location', 'services', 'tone', 'target_audience', 'differentiators', 'pricing_anchor', 'domain'];

    protected $fillable = [
        'workspace_id', 'name', 'slug', 'aliases_json', 'industry', 'services_json', 'goal', 'location', 'brand_color', 'logo_url',
        'tone', 'target_audience', 'differentiators', 'pricing_anchor', 'settings_json', 'is_default', 'sort_order',
    ];

    protected $casts = ['aliases_json' => 'array', 'services_json' => 'array', 'settings_json' => 'array', 'is_default' => 'boolean'];

    public function workspace() { return $this->belongsTo(Workspace::class); }

    protected static function booted(): void
    {
        static::saved(function (Business $b) {
            if ($b->is_default) { $b->mirrorToWorkspace(); }
        });
    }

    /** A slug unique within the workspace. */
    public static function slugFor(int $wsId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'business'; $slug = $base; $n = 2;
        while (static::withTrashed()->where('workspace_id', $wsId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) { $slug = $base . '-' . $n++; }
        return $slug;
    }

    /**
     * The write-through mirror: the default business's profile fields are copied onto workspaces.* (no model events,
     * so nothing loops), only where a value differs.
     */
    public function mirrorToWorkspace(): void
    {
        $ws = DB::table('workspaces')->where('id', $this->workspace_id)->first();
        if (! $ws) { return; }
        $upd = [];
        foreach (self::MIRROR as $wsCol => $bCol) {
            $val = $this->{$bCol};
            if ($bCol === 'services_json') {
                $new = is_array($val) ? $val : (is_string($val) ? (json_decode($val, true) ?? []) : []);
                $cur = is_string($ws->$wsCol ?? null) ? (json_decode($ws->$wsCol, true) ?? []) : [];
                if ($cur != $new) { $upd[$wsCol] = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
                continue;
            }
            if ((string) ($ws->$wsCol ?? '') !== (string) ($val ?? '')) { $upd[$wsCol] = $val; }
        }
        if ($upd) { $upd['updated_at'] = now(); DB::table('workspaces')->where('id', $this->workspace_id)->update($upd); }
    }

    /** The reverse mirror: a workspace whose profile columns were written (Settings, onboarding) updates its default business. */
    public static function syncDefaultFromWorkspace(int $wsId): void
    {
        $b = static::where('workspace_id', $wsId)->where('is_default', true)->first();
        if (! $b) { return; }
        $ws = DB::table('workspaces')->where('id', $wsId)->first();
        if (! $ws) { return; }
        $changed = false;
        foreach (self::MIRROR as $wsCol => $bCol) {
            $val = $ws->$wsCol ?? null;
            if ($bCol === 'services_json') {
                $val = is_string($val) ? (json_decode($val, true) ?? []) : (is_array($val) ? $val : []);
                if (($b->services_json ?? []) != $val) { $b->services_json = $val; $changed = true; }
                continue;
            }
            if ($bCol === 'name' && trim((string) $val) === '') { continue; } // an empty business_name never blanks the business
            if ((string) ($b->$bCol ?? '') !== (string) ($val ?? '')) { $b->$bCol = $val; $changed = true; }
        }
        if ($changed) { $b->saveQuietly(); } // quiet: workspaces.* already carries these values
    }
}
