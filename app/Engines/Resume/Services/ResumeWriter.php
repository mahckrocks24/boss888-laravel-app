<?php

namespace App\Engines\Resume\Services;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;

/**
 * RESUME888 — the only place the model is called. Stateless, cache-friendly prompts (constant prefix first),
 * JSON out, validated here, content-hash cached in resume_cache so repeats are free. Cost is estimated per
 * call and returned to the caller for the session ledger. White-label: the persona is "Kabayan CV Assistant".
 */
class ResumeWriter
{
    // USD per 1M tokens, list prices (DeepSeek chat cache-miss; vision ≈ gpt-4o). Conservative on purpose.
    public const PRICE_IN = 0.27, PRICE_OUT = 1.10, VISION_IN = 2.50, VISION_OUT = 10.00;

    public const SYSTEM = "You are Kabayan CV Assistant, a careful resume writer for Filipino workers applying in the Gulf. Rules: never invent employers, dates, degrees, licences, numbers or skills; only rephrase and structure what the candidate said; write the CV in clear professional English; bullets start with a strong verb, are concrete, one line each, no first person, no fluff; keep the candidate's facts exactly. Never mention any AI provider, model or company. Respond with valid JSON only.";

    private ?RuntimeClient $runtime = null;
    /** Lazy: the runtime client throws when RUNTIME_URL is unset (tests, form mode); we only need it on a real call. */
    private function runtime(): RuntimeClient { return $this->runtime ??= app(RuntimeClient::class); }

    private function cached(string $kind, string $input): ?array
    {
        $key = hash('sha256', $kind . '|' . $input);
        $row = DB::table('resume_cache')->where('cache_key', $key)->first();
        if (!$row) return null;
        DB::table('resume_cache')->where('cache_key', $key)->increment('hits');
        return json_decode($row->value_json, true);
    }
    private function store(string $kind, string $input, array $value): void
    {
        DB::table('resume_cache')->updateOrInsert(['cache_key' => hash('sha256', $kind . '|' . $input)], ['kind' => $kind, 'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
    public static function estimateUsdMicro(int $inChars, int $outChars, bool $vision = false): int
    {
        $in = $inChars / 4; $out = $outChars / 4;
        $usd = $vision ? ($in * self::VISION_IN + $out * self::VISION_OUT) / 1e6 : ($in * self::PRICE_IN + $out * self::PRICE_OUT) / 1e6;
        return (int) ceil($usd * 1e6);
    }

    /** @return array{ok:bool, data:array, cost:int, cached:bool, error?:string} */
    private function call(string $kind, string $prompt, int $maxTokens = 500): array
    {
        if ($hit = $this->cached($kind, $prompt)) return ['ok' => true, 'data' => $hit, 'cost' => 0, 'cached' => true];
        try { $res = $this->runtime()->chatJson(self::SYSTEM, $prompt, ['task' => 'resume.' . $kind], $maxTokens); }
        catch (\Throwable $e) { return ['ok' => false, 'data' => [], 'cost' => 0, 'cached' => false, 'error' => 'runtime_unavailable']; }
        $cost = self::estimateUsdMicro(strlen(self::SYSTEM) + strlen($prompt), (int) ($maxTokens * 4 * 0.6));
        if (empty($res['success']) || !is_array($res['parsed'] ?? null)) return ['ok' => false, 'data' => [], 'cost' => $cost, 'cached' => false, 'error' => (string) ($res['error'] ?? $res['parse_error'] ?? 'no_json')];
        $this->store($kind, $prompt, $res['parsed']);
        return ['ok' => true, 'data' => $res['parsed'], 'cost' => $cost, 'cached' => false];
    }

    /** One job → 3–5 bullets from the candidate's raw words. */
    public function bullets(array $job, array $target = []): array
    {
        $raw = trim((string) ($job['raw'] ?? '')); $proud = trim((string) ($job['proud'] ?? ''));
        if ($raw === '' && $proud === '') return ['ok' => true, 'data' => ['bullets' => []], 'cost' => 0, 'cached' => true];
        $prompt = "Task: write 3 to 5 CV bullets for this job, in English, from the candidate's own words. Return JSON {\"bullets\":[\"…\"]}.\n"
            . "Job title: " . ($job['title'] ?? '') . "\nEmployer: " . ($job['employer'] ?? '') . "\nTarget roles: " . implode(', ', (array) ($target['roles'] ?? [])) . "\n"
            . "Candidate's words (may be Taglish/Filipino; translate faithfully, do not add facts): " . mb_substr($raw, 0, 1200) . ($proud !== '' ? "\nProud of: " . mb_substr($proud, 0, 400) : '');
        $r = $this->call('bullets', $prompt, 350);
        if ($r['ok']) $r['data']['bullets'] = self::cleanList($r['data']['bullets'] ?? [], 5, 160);
        return $r;
    }

    /** Professional summary from the whole draft (facts only). */
    public function summary(array $draft): array
    {
        $jobs = array_slice($draft['experience'] ?? [], 0, 4);
        $lines = array_map(fn ($j) => ($j['title'] ?? '') . ' at ' . ($j['employer'] ?? '') . ' (' . ($j['start'] ?? '?') . ' to ' . ($j['end'] ?? '?') . ')', $jobs);
        $prompt = "Task: write a 2–3 sentence professional summary in English for the top of a CV. Facts only from below; no adjectives about character unless supported. Return JSON {\"summary\":\"…\"}.\n"
            . "Target roles: " . implode(', ', (array) ($draft['target']['roles'] ?? [])) . "\nCountries: " . implode(', ', (array) ($draft['target']['countries'] ?? [])) . "\n"
            . "Experience: " . implode('; ', $lines) . "\nSkills: " . implode(', ', array_slice((array) ($draft['skills'] ?? []), 0, 12)) . "\nLicences: " . implode(', ', array_map(fn ($l) => is_array($l) ? ($l['name'] ?? '') : (string) $l, (array) ($draft['licences'] ?? [])))
            . "\nEducation: " . implode('; ', array_map(fn ($e) => ($e['qualification'] ?? '') . ' ' . ($e['school'] ?? ''), (array) ($draft['education'] ?? [])));
        $r = $this->call('summary', $prompt, 220);
        if ($r['ok']) $r['data']['summary'] = mb_substr(trim((string) ($r['data']['summary'] ?? '')), 0, 600);
        return $r;
    }

    /** "Improve this" on one text (bullet or summary). */
    public function improve(string $text, string $context = ''): array
    {
        $prompt = "Task: improve this CV text. Keep every fact, make it concrete, active and concise, English. Return JSON {\"text\":\"…\"}.\nContext: " . mb_substr($context, 0, 200) . "\nText: " . mb_substr(trim($text), 0, 600);
        $r = $this->call('improve', $prompt, 200);
        if ($r['ok']) $r['data']['text'] = mb_substr(trim((string) ($r['data']['text'] ?? '')), 0, 600);
        return $r;
    }

    /** Uploaded CV text → schema draft with per-field confidence. One call on the (already rule-split) text. */
    public function extract(string $text): array
    {
        $prompt = "Task: extract this CV into JSON with keys person{full_name,headline,email,phone,city,country,nationality,visa_status}, target{roles[]}, summary, experience[{employer,title,city,start,end,raw}], education[{school,qualification,field,year}], licences[{name,issuer,expires}], skills[], languages[{name,level}], confidence{field:0-1}. Dates as YYYY-MM or 'present'. Copy the candidate's own bullet text into each job's raw (joined with newlines). Unknown → null or []. Never invent.\nCV text:\n" . mb_substr($text, 0, 9000);
        return $this->call('extract', $prompt, 1400);
    }

    public static function cleanList(array $items, int $max, int $len): array
    {
        $out = [];
        foreach ($items as $b) { $b = trim(preg_replace('/\s+/', ' ', (string) $b)); $b = preg_replace('/^[-•*]\s*/', '', $b); if ($b === '') continue; $out[] = mb_substr($b, 0, $len); if (count($out) >= $max) break; }
        return $out;
    }

    /** Zero-cost fallback (form mode / budget exhausted): bullets from the candidate's own sentences. */
    public static function templatedBullets(array $job): array
    {
        $raw = trim((string) ($job['raw'] ?? '')) . ' ' . trim((string) ($job['proud'] ?? ''));
        $parts = preg_split('/[.;\n]+|,\s+(?=[A-Za-z])/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) { $p = trim($p); if (mb_strlen($p) < 6) continue; $out[] = mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1, 150); if (count($out) >= 5) break; }
        return $out;
    }
    public static function templatedSummary(array $draft): string
    {
        $roles = implode(', ', (array) ($draft['target']['roles'] ?? [])); $jobs = $draft['experience'] ?? [];
        $years = 0; foreach ($jobs as $j) { $s = strtotime(($j['start'] ?? '') . '-01'); $e = ($j['end'] ?? '') === 'present' ? time() : strtotime(($j['end'] ?? '') . '-01'); if ($s && $e && $e > $s) $years += ($e - $s) / 31557600; }
        $years = (int) floor($years);
        $first = $jobs[0] ?? null;
        $s = ($first ? ($first['title'] ?? 'Professional') : 'Professional') . ($years > 0 ? " with {$years}+ years of experience" : '') . ($first && !empty($first['employer']) ? " including {$first['employer']}" : '') . '.';
        if ($roles !== '') $s .= " Seeking a role as {$roles}" . (!empty($draft['target']['countries']) ? ' in ' . implode(' or ', (array) $draft['target']['countries']) : '') . '.';
        if (!empty($draft['skills'])) $s .= ' Skills include ' . implode(', ', array_slice((array) $draft['skills'], 0, 5)) . '.';
        return $s;
    }
}
