<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN-LIBRARY-2: imports the compiled reference library (design-library.json) into design_recipes, upserting by key,
 * so a re-run after the Owner curates the references only adds and updates. Never touches thumbnails already rendered.
 *   php artisan design-library:import storage/app/design-library/design-library.json
 */
class DesignLibraryImportCommand extends Command
{
    protected $signature = 'design-library:import {path=storage/app/design-library/design-library.json} {--deactivate-missing : deactivate rows whose key is no longer in the file}';
    protected $description = 'Import the design reference library (recipes + prompt templates) into design_recipes';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        if (! is_file($path)) { $this->error("No file at {$path}"); return 1; }
        $lib = json_decode((string) file_get_contents($path), true);
        if (! is_array($lib) || empty($lib['industries'])) { $this->error('Not a library file'); return 1; }
        $seen = []; $added = 0; $updated = 0; $sort = 0;
        foreach ($lib['industries'] as $industry => $rows) {
            foreach ($rows as $r) {
                $key = mb_substr((string) ($r['industry'] ?? $industry), 0, 40) . ':' . (string) ($r['id'] ?? md5((string) ($r['image'] ?? '')));
                $key = strtolower(preg_replace('/[^a-z0-9:]+/i', '-', $key));
                $vars = array_values((array) ($r['variables'] ?? []));
                $hasPeople = (bool) array_filter($vars, fn ($v) => str_starts_with((string) ($v['name'] ?? ''), 'PERSON'));
                $img = (array) ($r['imagery'] ?? []); $ty = (array) ($r['typography'] ?? []);
                $tagWords = array_filter(array_merge(
                    [$industry, (string) ($r['archetype'] ?? ''), (string) ($r['format'] ?? ''), (string) ($r['mood'] ?? ''), (string) ($img['kind'] ?? ''), (string) ($img['subject_type'] ?? ''), (string) ($img['grade'] ?? ''), (string) (($ty['headline'] ?? [])['family_feel'] ?? ''), (string) (($ty['headline'] ?? [])['case'] ?? ''), (string) ($r['title'] ?? ''), $hasPeople ? 'people person' : 'no people'],
                    (array) ($r['direction_fit'] ?? []), (array) ($r['effects'] ?? []), (array) ($r['graphic_elements'] ?? [])
                ));
                $tags = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', $tagWords))));
                $seen[] = $key; $sort++;
                $data = [
                    'industry' => (string) $industry, 'title' => mb_substr((string) ($r['title'] ?? 'Untitled look'), 0, 120),
                    'archetype' => mb_substr((string) ($r['archetype'] ?? 'other'), 0, 60), 'format' => mb_substr((string) ($r['format'] ?? 'portrait_4_5'), 0, 20),
                    'direction_fit' => json_encode(array_values((array) ($r['direction_fit'] ?? []))), 'mood' => mb_substr((string) ($r['mood'] ?? ''), 0, 160),
                    'colour_json' => json_encode((array) ($r['colour'] ?? [])), 'tags' => $tags, 'has_people' => $hasPeople,
                    'recipe_json' => json_encode(array_diff_key($r, array_flip(['prompt_template'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'prompt_template' => (string) ($r['prompt_template'] ?? ''), 'variables_json' => json_encode($vars, JSON_UNESCAPED_UNICODE),
                    'active' => true, 'sort' => $sort, 'updated_at' => now(),
                ];
                $ex = DB::table('design_recipes')->where('key', $key)->first(['id']);
                if ($ex) { DB::table('design_recipes')->where('id', $ex->id)->update($data); $updated++; }
                else { DB::table('design_recipes')->insert($data + ['key' => $key, 'created_at' => now()]); $added++; }
            }
        }
        $deact = 0;
        if ($this->option('deactivate-missing')) { $deact = DB::table('design_recipes')->whereNotIn('key', $seen)->where('active', true)->update(['active' => false, 'updated_at' => now()]); }
        $this->info("design-library: added {$added}, updated {$updated}, deactivated {$deact}, total active " . DB::table('design_recipes')->where('active', true)->count());
        return 0;
    }
}
