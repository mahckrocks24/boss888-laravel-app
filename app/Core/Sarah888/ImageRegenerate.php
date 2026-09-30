<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REGEN-1 (RISK-0207, Owner 2026-09-30: "Add regenerate option and push the 2nd attempt directly to openai just make sure
 * there is enough parameters on the prompt towards it so it matches the requirement exactly including text").
 *
 * First attempt = the renderer (text-free photo + exact typography laid over it, honouring the brief). When the owner is
 * not happy - "regenerate", "redo", "generate a better one", "try again" - the second attempt goes to the image model as
 * ONE generation with the text painted into the picture, driven by the same brief (headline, style, placement, colours)
 * turned into explicit parameters. Same cost as an image; the owner still says yes first.
 */
final class ImageRegenerate
{
    public static function asks(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 200) return false;
        return (bool) preg_match('/\b(regenerate|re-?generate|regen|redo( it| that| the (image|banner|picture))?|do it again|try again|another (go|attempt|try|version|one)|generate a better one|make (it |that )?better|a better one|not (good|right|happy)( with (it|that))?|redo the text|fix the text|paint the (text|words)|bake (the )?(text|words)( in)?|second attempt)\b/u', $t);
    }

    /**
     * The regenerate spec for the last image Sarah showed in this workspace, or null when there is none to redo.
     * @return array{prompt:string,action:string,cost:int,regen:true,headline:string,supporting_copy:array,text_style:string,text_placement:string,retry_of_media_id:int,retry_of_url:string,original_prompt:string}|null
     */
    public static function spec(int $wsId): ?array
    {
        $last = LastImageContext::lastShown($wsId, 48);   // a redo can come the next day
        if (! $last) return null;
        return self::fromMedia($wsId, (string) $last['url']);
    }

    /** The spec for one shown image (URL or filename). */
    public static function fromMedia(int $wsId, string $urlOrFile): ?array
    {
        $last = ['url' => $urlOrFile];
        $file = basename(parse_url((string) $last['url'], PHP_URL_PATH) ?: (string) $last['url']);
        // the composed file shares its stem with the text-free original; look both up
        $stem = preg_replace('/-composed(?=\.png$)/', '', $file);
        // several media rows can share a file (the asset and its library copy): take the one that carries the brief
        $rows = DB::table('media')->where('workspace_id', $wsId)->where(fn ($q) => $q->where('filename', $file)->orWhere('filename', $stem))->orderByDesc('id')->get();
        if ($rows->isEmpty()) return null;
        $row = $rows->first(fn ($r) => is_array((json_decode((string) $r->metadata_json, true) ?: [])['blueprint']['typography_strategy'] ?? null)) ?: $rows->first();
        $meta = json_decode((string) $row->metadata_json, true) ?: [];
        $bp   = is_array($meta['blueprint'] ?? null) ? $meta['blueprint'] : [];
        $ts   = is_array($bp['typography_strategy'] ?? null) ? $bp['typography_strategy'] : [];
        $headline = trim((string) ($ts['headline'] ?? ''), " \t\"'\u{201C}\u{201D}\u{2018}\u{2019}");
        $copy     = array_values(array_filter(array_map('trim', (array) ($ts['supporting_copy'] ?? []))));
        $original = trim((string) ($meta['original_prompt'] ?? ''));
        $subject  = trim((string) ($bp['subject'] ?? ''));
        // the brief the model gets: the owner's own ask first (it is what they judged), else the blueprint's subject
        $prompt = $original !== '' ? $original : ($subject !== '' ? $subject : trim((string) $row->prompt));
        if ($prompt === '') return null;
        $action = ((int) $row->width * (int) $row->height) > 1_100_000 ? 'generate_image_high' : 'generate_image';
        if ($action === 'generate_image_high') $action = 'generate_image';   // the redo costs the standard image, never the premium tier by accident
        return [
            'prompt'             => $prompt,
            'action'             => $action,
            'cost'               => ImageGeneration::costFor($action),
            'regen'              => true,
            'headline'           => $headline,
            'supporting_copy'    => $copy,
            'text_style'         => trim((string) ($ts['style'] ?? '')),
            'text_placement'     => trim((string) ($ts['placement'] ?? '')),
            'retry_of_media_id'  => (int) $row->id,
            'retry_of_url'       => (string) $last['url'],
            'original_prompt'    => $original,
            'aspect'             => (int) $row->width > 0 && (int) $row->height > 0 ? ((int) $row->width . 'x' . (int) $row->height) : '',
        ];
    }

    /** Sarah's offer for the redo: what changes, the exact words, the cost, then a yes. */
    public static function describe(array $spec): string
    {
        $cost = (int) ($spec['cost'] ?? 4);
        $hl   = trim((string) ($spec['headline'] ?? ''));
        $line = $hl !== ''
            ? "I'll redo it as one picture with the words painted in by the image model itself — the headline \"{$hl}\" spelled exactly, in the same style and placement as the brief"
            : "I'll redo it as a fresh picture from the same brief";
        return $line . ". That's {$cost} credit" . ($cost === 1 ? '' : 's') . ". Say **yes** to go ahead, or **no** to keep the current one.";
    }
}
