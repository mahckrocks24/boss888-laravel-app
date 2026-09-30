<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * DESIGN-LIBRARY-2: our own rendering of each recipe for the library tiles (the reference images never enter the
 * product). The recipe's prompt template is filled with a house demo brand and the reference's own variable values, and
 * sent to the image model exactly as Sarah will send it for a customer - so every thumbnail is also a live test of the
 * prompt. Rows without a thumbnail are rendered; --ids or --limit narrow a run.
 *   php artisan design-library:thumbs --limit=12
 */
class DesignLibraryThumbsCommand extends Command
{
    protected $signature = 'design-library:thumbs {--limit=0} {--ids=} {--quality=medium} {--redo : re-render rows that already have a thumbnail}';
    protected $description = 'Render the library thumbnails with the image model from each recipe\'s own prompt';

    private const HEADLINES = ['statement over scene' => 'Made For Moments', 'product hero' => 'New This Season', 'offer banner' => 'Weekend Offer', 'quote card' => 'Small Steps, Big Change', 'before-after' => 'Then And Now', 'listing card' => 'Just Listed', 'checklist/tips' => 'Three Simple Tips', 'testimonial' => 'What Clients Say', 'announcement' => 'Now Open', 'event poster' => 'Join Us Friday', 'menu/price card' => 'Our Favourites', 'team/portrait' => 'Meet The Team', 'lifestyle photo' => 'Your Kind Of Day', 'editorial carousel cover' => 'Five Things To Know', 'other' => 'Made For You'];

    public function handle(): int
    {
        $q = DB::table('design_recipes')->where('active', true);
        if ($ids = trim((string) $this->option('ids'))) $q->whereIn('id', array_map('intval', explode(',', $ids)));
        elseif (! $this->option('redo')) $q->whereNull('thumb_path');
        $q->orderBy('sort'); if ((int) $this->option('limit') > 0) $q->limit((int) $this->option('limit'));
        $rows = $q->get(); $runtime = app(\App\Connectors\RuntimeClient::class);
        $ok = 0; $fail = 0;
        foreach ($rows as $r) {
            $recipe = json_decode((string) $r->recipe_json, true) ?: [];
            $prompt = $this->fill((string) $r->prompt_template, $r, $recipe);
            $size = $r->format === 'square' ? '1024x1024' : '1024x1536';
            DB::table('design_recipes')->where('id', $r->id)->update(['thumb_status' => 'rendering', 'updated_at' => now()]);
            $img = $runtime->imageGenerate($prompt, ['workspace_id' => 1, 'style' => 'natural', 'size' => $size, 'quality' => (string) $this->option('quality')]);
            if (empty($img['success']) || empty($img['url'])) { $fail++; DB::table('design_recipes')->where('id', $r->id)->update(['thumb_status' => 'failed', 'updated_at' => now()]); $this->warn("#{$r->id} {$r->title}: " . ($img['error'] ?? 'no image')); continue; }
            try {
                $bytes = ! empty($img['storage_path']) && Storage::disk('public')->exists($img['storage_path']) ? Storage::disk('public')->get($img['storage_path']) : (string) file_get_contents($img['url']);
                $src = imagecreatefromstring($bytes); if (! $src) throw new \RuntimeException('decode');
                $w = imagesx($src); $h = imagesy($src);
                $full = 'design-library/' . $r->key . '.jpg'; ob_start(); imagejpeg($src, null, 88); Storage::disk('public')->put($full, (string) ob_get_clean());
                $tw = 480; $th = (int) round($h * $tw / $w); $dst = imagecreatetruecolor($tw, $th); imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                $thumb = 'design-library/thumbs/' . $r->key . '.jpg'; ob_start(); imagejpeg($dst, null, 82); Storage::disk('public')->put($thumb, (string) ob_get_clean());
                imagedestroy($src); imagedestroy($dst);
                DB::table('design_recipes')->where('id', $r->id)->update(['thumb_path' => $thumb, 'thumb_status' => 'done', 'updated_at' => now()]);
                $ok++; $this->info("#{$r->id} {$r->title} -> {$thumb}");
            } catch (\Throwable $e) { $fail++; DB::table('design_recipes')->where('id', $r->id)->update(['thumb_status' => 'failed', 'updated_at' => now()]); $this->warn("#{$r->id} store failed: " . $e->getMessage()); }
            usleep(500000);
        }
        $this->info("thumbs: rendered {$ok}, failed {$fail}");
        return 0;
    }

    /** The template filled exactly as the engine will fill it: brand placeholders from a demo kit, variables from the reference's own values. */
    public function fill(string $tpl, object $r, array $recipe): string
    {
        $c = json_decode((string) $r->colour_json, true) ?: [];
        $ty = (array) ($recipe['typography'] ?? []); $h = (array) ($ty['headline'] ?? []);
        $vals = [
            '{BRAND_NAME}' => 'Northwind', '{HEADLINE}' => self::HEADLINES[$r->archetype] ?? 'Made For You',
            '{SUBHEAD}' => 'Book your visit this week', '{SMALL_TEXT}' => 'northwind.co',
            '{PRIMARY_HEX}' => $c['accent'] ?? '#6C5CE7', '{ACCENT_HEX}' => $c['accent'] ?? '#00E5A8', '{GROUND_HEX}' => $c['ground'] ?? '#111111', '{TEXT_HEX}' => $c['text'] ?? '#FFFFFF',
            '{HEADING_FONT_FEEL}' => (string) ($h['family_feel'] ?? 'clean geometric sans'),
            '{FORMAT}' => ['square' => 'a square 1:1 social post', 'portrait_4_5' => 'a portrait 4:5 social post', 'story_9_16' => 'a vertical 9:16 story'][$r->format] ?? 'a portrait 4:5 social post',
            '{FORMAT_PX}' => $r->format === 'square' ? '1024x1024' : '1024x1536',
        ];
        foreach ((array) ($recipe['variables'] ?? []) as $v) { if (! empty($v['name'])) $vals['{' . $v['name'] . '}'] = (string) ($v['reference_value'] ?? $v['controls'] ?? ''); }
        $out = strtr($tpl, $vals);
        // a placeholder the recipe declared nowhere: a plain, safe value
        return (string) preg_replace_callback('/\{([A-Z_0-9]+)\}/', fn ($m) => str_starts_with($m[1], 'PERSON') ? 'a person' : (str_starts_with($m[1], 'SETTING') ? 'a fitting place' : (str_starts_with($m[1], 'PRODUCT') ? 'the product' : 'it')), $out);
    }
}
