<?php

namespace App\Core\Brand;

use Illuminate\Support\Facades\DB;

/**
 * RECIPE-1: the variables a recipe leaves open (people, place, product, occasion, props) filled "in accordance to the
 * preference of the user" (Owner 2026-10-01). Preferences are remembered per business in directions_json.variables;
 * the request's own words win for one post; what is still unknown gets a sensible default drawn from the brand kit and
 * a note so Sarah can ask once.
 */
final class RecipeVariables
{
    /** @return array{values:array<string,string>, missing:array<string>, note:?string} */
    public static function resolve(int $wsId, ?int $bizId, object $recipe, string $userPrompt, array $brand): array
    {
        $rj = json_decode((string) $recipe->recipe_json, true) ?: [];
        $declared = array_values(array_filter(array_map(fn ($v) => strtoupper((string) ($v['name'] ?? '')), (array) ($rj['variables'] ?? []))));
        $prefs = self::prefs($wsId, $bizId);
        $values = []; $missing = [];
        $fromPrompt = self::fromPrompt($userPrompt);
        foreach ($declared as $name) {
            $base = preg_replace('/_\d+$/', '', $name);
            $v = $fromPrompt[$name] ?? $fromPrompt[$base] ?? $prefs[$name] ?? $prefs[$base] ?? null;
            if ($v === null) {
                if ($base === 'PERSON') { $aud = trim((string) ($brand['target_audience'] ?? '')); $v = $aud !== '' ? "a person from the business's audience ({$aud}), natural and unposed" : null; }
                elseif ($base === 'SETTING') { $ind = trim((string) ($brand['industry'] ?? '')); $v = $ind !== '' ? "the business's own {$ind} setting" : null; }
                elseif ($base === 'PRODUCT') { $v = self::productFromPrompt($userPrompt); }
                elseif ($base === 'OCCASION') { $v = self::occasionFromPrompt($userPrompt) ?? 'an everyday moment'; }
                elseif ($base === 'PROPS') { $v = 'a few props that belong to the business, nothing branded'; }
            }
            if ($v === null || trim((string) $v) === '') { $missing[] = $name; continue; }
            $values[$name] = trim((string) $v);
        }
        $note = null;
        if (in_array('PERSON_1', $missing, true) || (isset($values['PERSON_1']) && str_starts_with($values['PERSON_1'], "a person from the business's audience") && empty($prefs['PERSON']) && empty($prefs['PERSON_1']))) {
            $note = "Tell me who should appear in your pictures (for example \"young families\", \"Filipino couples in their 30s\", \"athletic men and women\") and I'll keep to it from now on.";
        }
        return ['values' => $values, 'missing' => $missing, 'note' => $note];
    }

    public static function prefs(int $wsId, ?int $bizId): array
    {
        try {
            $p = app(BrandProfileService::class); $row = $p->row($wsId, $p->business($wsId, $bizId), false);
            $d = $row ? BrandProfileService::json($row, 'directions_json') : [];
            return array_change_key_case(array_filter((array) ($d['variables'] ?? []), 'is_string'), CASE_UPPER);
        } catch (\Throwable) { return []; }
    }

    /** Remember a preference for this business ("PERSON" applies to every PERSON_n unless a numbered one exists). */
    public static function remember(int $wsId, ?int $bizId, string $key, string $value, string $source = 'chat'): void
    {
        try {
            $p = app(BrandProfileService::class); $biz = $p->business($wsId, $bizId); $row = $p->row($wsId, $biz, true);
            $d = BrandProfileService::json($row, 'directions_json'); $vars = (array) ($d['variables'] ?? []);
            $vars[strtoupper($key)] = mb_substr(trim($value), 0, 160); $d['variables'] = $vars;
            $p->write($row, ['directions_json' => json_encode($d, JSON_UNESCAPED_UNICODE)], $source, 'design variable preference remembered: ' . strtoupper($key));
        } catch (\Throwable) {}
    }

    /**
     * The owner states a standing preference in chat: "in my pictures use Asian families", "the people in our images
     * should be athletic women", "show our own restaurant as the setting". Returns the key stored, or null.
     */
    public static function absorb(int $wsId, string $text, ?int $bizId = null): ?string
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 300) return null;
        if (preg_match('/\b(?:in|for|on)\s+(?:my|our)\s+(?:pictures|images|photos|posts|designs|visuals)\b[^.]*?\b(?:use|show|feature|put|have)\s+([^.;!?]{3,120})/iu', $t, $m)
            || preg_match('/\b(?:the\s+)?(?:people|persons|models|faces)\s+(?:in|on)\s+(?:my|our)\s+(?:pictures|images|photos|posts|designs)\s+(?:should|must|need to)\s+(?:be|look like|show)\s+([^.;!?]{3,120})/iu', $t, $m)
            || preg_match('/\b(?:always|from now on)\s+(?:use|show|feature)\s+([^.;!?]{3,120})\s+(?:in|on)\s+(?:my|our)\s+(?:pictures|images|photos|posts|designs)/iu', $t, $m)) {
            $v = trim($m[1]); $key = preg_match('/\b(setting|place|location|interior|kitchen|shop|studio|office|gym|clinic|venue)\b/i', $v) && ! preg_match('/\b(man|woman|men|women|people|family|families|couple|customer|client|model|person)\b/i', $v) ? 'SETTING' : 'PERSON';
            self::remember($wsId, $bizId, $key, $v);
            return $key;
        }
        return null;
    }

    /** People or setting named in THIS request win for this post only. */
    private static function fromPrompt(string $p): array
    {
        $out = [];
        if (preg_match('/\b((?:an?\s+)?(?:young|old|elderly|middle-aged|smiling|happy|athletic|fit|senior|teen)?\s*(?:black|white|asian|filipino|filipina|chinese|japanese|korean|indian|arab|latino|latina|hispanic|african|european|caucasian|mixed-race)\s+(?:man|woman|men|women|family|families|couple|chef|person|people|boy|girl|kids?|children|customers?|models?)[^,.;]{0,50})/iu', $p, $m)) $out['PERSON'] = trim($m[1]);
        if (preg_match('/\b(?:in|at|inside)\s+(our|my|the)\s+((?:own\s+)?(?:restaurant|kitchen|shop|store|studio|office|gym|clinic|salon|showroom|venue|garden|hotel|lobby|workshop)[^,.;]{0,40})/iu', $p, $m)) $out['SETTING'] = trim($m[1] . ' ' . $m[2]);
        return $out;
    }
    private static function productFromPrompt(string $p): ?string
    {
        if (preg_match('/\b(?:for|of|featuring|showing|with)\s+(?:our|my|the)\s+((?:new\s+)?[a-z][a-z\- ]{2,40}?)(?=\s+(?:on|for|in|at|to|with|and)\b|[,.;!?]|$)/iu', $p, $m)) return "the business's " . trim($m[1]);
        return null;
    }
    private static function occasionFromPrompt(string $p): ?string
    {
        if (preg_match('/\b(christmas|holiday season|holidays|new year|valentine\'?s?|easter|ramadan|eid|diwali|thanksgiving|halloween|black friday|summer|winter|spring|autumn|fall|weekend|grand opening|anniversary|birthday|mother\'?s day|father\'?s day)\b/i', $p, $m)) return strtolower($m[1]);
        return null;
    }
}
