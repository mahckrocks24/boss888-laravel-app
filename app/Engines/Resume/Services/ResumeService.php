<?php

namespace App\Engines\Resume\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * RESUME888 — session lifecycle, deterministic turn handling, budgets and caps, extraction, finalisation,
 * rendering, email, deletion, purge. The model is only touched through ResumeWriter (bullets, summary,
 * improve, extract) and only when the site's budget allows; otherwise form mode keeps everything working.
 */
class ResumeService
{
    public const TOKEN_TTL_DAYS = 30, SAVED_TTL_DAYS = 365;
    public const MAX_TURNS = 60, MAX_MODEL_CALLS = 8, MAX_VISION_CALLS = 3;
    public const DEFAULT_DAILY_USD = 3.0, DEFAULT_MONTHLY_USD = 30.0;
    public const DEVICE_SESSIONS_PER_DAY = 5, DEVICE_CVS_PER_DAY = 3, IP_SESSIONS_PER_DAY = 20, IP_CVS_PER_DAY = 10;

    public function __construct(private ResumeWriter $writer, private ResumeExtractor $extractor) {}

    // ─── policy ─────────────────────────────────────────────────────────────

    public function policy(object $website): array
    {
        $s = is_string($website->settings_json ?? null) ? (json_decode($website->settings_json, true) ?: []) : [];
        $r = (array) ($s['resume'] ?? []);
        return ['enabled' => (bool) ($r['enabled'] ?? true), 'daily_usd' => (float) ($r['daily_budget_usd'] ?? self::DEFAULT_DAILY_USD), 'monthly_usd' => (float) ($r['monthly_budget_usd'] ?? self::DEFAULT_MONTHLY_USD)];
    }

    /** 'full' | 'economy' | 'form' from spend vs budget. */
    public function mode(int $wid, array $policy): string
    {
        if ($policy['daily_usd'] <= 0 || $policy['monthly_usd'] <= 0) return 'form'; // a zero budget = never call the model
        $day = (int) DB::table('resume_events')->where('website_id', $wid)->where('created_at', '>=', now()->startOfDay())->sum('cost_usd_micro');
        $month = (int) DB::table('resume_events')->where('website_id', $wid)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd_micro');
        $d = $day / 1e6 / max(0.01, $policy['daily_usd']); $m = $month / 1e6 / max(0.01, $policy['monthly_usd']);
        $r = max($d, $m);
        return $r >= 1.0 ? 'form' : ($r >= 0.8 ? 'economy' : 'full');
    }

    private function event(int $wid, ?int $sid, string $event, int $cost = 0, array $meta = []): void
    {
        try { DB::table('resume_events')->insert(['website_id' => $wid, 'session_id' => $sid, 'event' => $event, 'cost_usd_micro' => $cost, 'meta_json' => $meta ? json_encode($meta) : null, 'created_at' => now()]); } catch (\Throwable) {}
    }

    private function ceilingHit(string $key, int $max): bool
    {
        $k = 'rs:' . $key . ':' . now()->format('Ymd');
        if (RateLimiter::attempts($k) >= $max) return true;
        RateLimiter::hit($k, 86400);
        return false;
    }

    // ─── sessions ───────────────────────────────────────────────────────────

    public function start(object $website, array $d, string $ip, ?string $ua): array
    {
        $policy = $this->policy($website);
        if (!$policy['enabled']) return ['success' => false, 'error' => 'DISABLED'];
        $device = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($d['device_id'] ?? '')); $device = $device !== '' ? mb_substr($device, 0, 64) : null;
        if ($this->ceilingHit('ip:' . $ip, self::IP_SESSIONS_PER_DAY) || ($device && $this->ceilingHit('dev:' . $device, self::DEVICE_SESSIONS_PER_DAY))) return ['success' => false, 'error' => 'DAILY_LIMIT'];
        $lang = in_array($d['language'] ?? '', ResumeInterview::LANGS, true) ? $d['language'] : 'tl';
        $plain = 'rs_' . bin2hex(random_bytes(24));
        $id = DB::table('resume_sessions')->insertGetId([
            'workspace_id' => (int) $website->workspace_id, 'website_id' => (int) $website->id, 'token_hash' => hash('sha256', $plain), 'device_id' => $device,
            'language' => $lang, 'region' => null, 'state' => 'path', 'draft_json' => json_encode(self::emptyDraft($lang)), 'progress_json' => json_encode(['job_index' => 0, 'answered' => []]),
            'consent_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS), 'ip' => $ip, 'user_agent' => mb_substr((string) $ua, 0, 255), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event((int) $website->id, $id, 'started', 0, ['lang' => $lang]);
        $s = $this->find($website, $plain);
        return ['success' => true, 'token' => $plain, 'session' => $this->publicSession($s, $website)];
    }

    public function find(object $website, string $plain): ?object
    {
        if (!preg_match('/^rs_[a-f0-9]{48}$/', $plain)) return null;
        $s = DB::table('resume_sessions')->where('website_id', (int) $website->id)->where('token_hash', hash('sha256', $plain))->whereNull('deleted_at')->first();
        if (!$s || ($s->expires_at && strtotime($s->expires_at) < time())) return null;
        DB::table('resume_sessions')->where('id', $s->id)->update(['last_seen_at' => now()]);
        return $s;
    }

    public static function emptyDraft(string $lang): array
    {
        return ['person' => ['full_name' => '', 'headline' => '', 'email' => '', 'phone' => '', 'city' => '', 'country' => '', 'nationality' => 'Filipino', 'visa_status' => '', 'availability' => '', 'driving_licence' => [], 'photo_path' => null],
            'target' => ['roles' => [], 'countries' => []], 'summary' => '', 'experience' => [], 'education' => [], 'licences' => [], 'skills' => [], 'languages' => [], 'references' => [],
            'meta' => ['language_of_conversation' => $lang, 'cv_language' => 'en', 'region' => null, 'template' => 'clean', 'source' => null, 'confidence' => []]];
    }

    public function publicSession(object $s, object $website): array
    {
        $draft = json_decode($s->draft_json, true) ?: []; $prog = json_decode($s->progress_json, true) ?: [];
        $mode = $this->mode((int) $website->id, $this->policy($website));
        return ['id' => (int) $s->id, 'language' => $s->language, 'path' => $s->path, 'region' => $s->region, 'state' => $s->state, 'completed' => !empty($s->completed_at), 'turns' => (int) $s->turns, 'mode' => $mode,
            'draft' => $draft, 'progress' => ResumeInterview::progress($s->state, $prog), 'step' => $this->stepFor($s, $draft, $prog), 'ui' => ResumeInterview::ui($s->language), 'email_saved' => !empty($s->email)];
    }

    private function stepFor(object $s, array $draft, array $prog): array
    {
        $step = ResumeInterview::step($s->state, $s->language, ['job_index' => (int) ($prog['job_index'] ?? 0), 'region' => $s->region ?? ($draft['meta']['region'] ?? '')]);
        if ($s->state === 'confirm' || $s->state === 'gaps') $step['gaps'] = $this->gaps($draft);
        return $step;
    }

    // ─── turns (no model call) ──────────────────────────────────────────────

    public function answer(object $website, object $s, string $stepId, $answer, bool $skip = false): array
    {
        if (!empty($s->completed_at)) return ['success' => false, 'error' => 'COMPLETED'];
        if ((int) $s->turns >= self::MAX_TURNS) return ['success' => false, 'error' => 'TURN_LIMIT'];
        if ($stepId !== $s->state) return ['success' => false, 'error' => 'STEP_MISMATCH', 'session' => $this->publicSession($s, $website)];
        $draft = json_decode($s->draft_json, true) ?: self::emptyDraft($s->language); $prog = json_decode($s->progress_json, true) ?: ['job_index' => 0];
        $n = (int) ($prog['job_index'] ?? 0);
        $step = ResumeInterview::step($stepId, $s->language, ['job_index' => $n, 'region' => $s->region ?? '']);
        $up = ['turns' => (int) $s->turns + 1, 'updated_at' => now()];
        if ($skip && !$step['skippable']) return ['success' => false, 'error' => 'REQUIRED', 'message' => ResumeInterview::ui($s->language)['required']];
        if (!$skip) {
            switch ($stepId) {
                case 'path': $path = $answer === 'upload' ? 'upload' : 'interview'; $up['path'] = $path; $draft['meta']['source'] = $path; break;
                case 'region': $reg = in_array($answer, ['AE', 'QA', 'ALL'], true) ? $answer : 'ALL'; $up['region'] = $reg; $draft['meta']['region'] = $reg; if ($reg !== 'ALL' && empty($draft['target']['countries'])) $draft['target']['countries'] = [$reg]; break;
                case 'job_more': $prog['add_job'] = ($answer === 'yes') && ($n + 1 < ResumeInterview::MAX_JOBS); if ($prog['add_job']) $prog['job_index'] = $n + 1; break;
                case 'photo': break; // handled by upload()
                default:
                    if ($step['kind'] === 'form') { foreach ((array) $step['fields'] as $f) { $v = is_array($answer) ? ($answer[$f['name']] ?? null) : null; if (!empty($f['required']) && ($v === null || trim((string) $v) === '')) return ['success' => false, 'error' => 'REQUIRED', 'field' => $f['name'], 'message' => ResumeInterview::ui($s->language)['required']]; self::set($draft, $f['name'], self::cleanValue($f, $v)); } }
                    elseif ($step['field']) {
                        $v = $answer;
                        if ($step['kind'] === 'multichips') $v = array_values(array_unique(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 60), (array) $v))));
                        elseif (!empty($step['list'])) $v = array_values(array_filter(array_map(fn ($x) => mb_substr(trim($x), 0, 60), preg_split('/[,;\n]+/', (string) $v) ?: [])));
                        else { $v = mb_substr(trim((string) $v), 0, $step['kind'] === 'textarea' ? 1500 : 200); if ($v === '' && !$step['skippable']) return ['success' => false, 'error' => 'REQUIRED', 'message' => ResumeInterview::ui($s->language)['required']]; }
                        if ($stepId === 'licences') $v = array_map(fn ($x) => ['name' => $x], (array) $v);
                        if ($stepId === 'languages') $v = array_map(fn ($x) => ['name' => $x], (array) $v);
                        if ($stepId === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'INVALID_EMAIL', 'message' => 'Email?'];
                        self::set($draft, $step['field'], $v);
                    }
            }
        }
        $prog['answered'][] = $stepId;
        $next = ResumeInterview::next($stepId, $prog, $up['path'] ?? $s->path ?? 'interview');
        $up['state'] = $next; $up['draft_json'] = json_encode($draft, JSON_UNESCAPED_UNICODE); $up['progress_json'] = json_encode($prog);
        DB::table('resume_sessions')->where('id', $s->id)->update($up);
        $this->event((int) $website->id, (int) $s->id, 'turn', 0, ['step' => $stepId]);
        $s = DB::table('resume_sessions')->where('id', $s->id)->first();
        if ($next === 'finish') return $this->finish($website, $s);
        return ['success' => true, 'session' => $this->publicSession($s, $website)];
    }

    private static function cleanValue(array $f, $v)
    {
        $v = mb_substr(trim((string) $v), 0, 120);
        if (($f['kind'] ?? '') === 'month' || ($f['kind'] ?? '') === 'month_or_present') return self::normMonth($v);
        if (($f['kind'] ?? '') === 'year') return preg_match('/(19|20)\d{2}/', $v, $m) ? $m[0] : $v;
        return $v;
    }
    public static function normMonth(string $v): string
    {
        $v = strtolower(trim($v)); if ($v === '' ) return '';
        if (preg_match('/present|now|current|ngayon|hanggang/', $v)) return 'present';
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $v, $m)) return sprintf('%04d-%02d', $m[1], min(12, max(1, (int) $m[2])));
        if (preg_match('/^(\d{1,2})[\s\/\-.](\d{4})$/', $v, $m)) return sprintf('%04d-%02d', $m[2], min(12, max(1, (int) $m[1])));
        if (preg_match('/^(\d{4})$/', $v, $m)) return $m[1] . '-01';
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12, 'ene' => 1, 'peb' => 2, 'abr' => 4, 'mayo' => 5, 'hun' => 6, 'hul' => 7, 'ago' => 8, 'set' => 9, 'okt' => 10, 'nob' => 11, 'dis' => 12];
        if (preg_match('/([a-z]{3})[a-z]*\.?\s*(\d{4})/', $v, $m) && isset($months[$m[1]])) return sprintf('%04d-%02d', $m[2], $months[$m[1]]);
        return $v;
    }
    public static function set(array &$a, string $path, $v): void
    {
        $ref = &$a; foreach (explode('.', $path) as $k) { if (!is_array($ref)) $ref = []; if (!array_key_exists($k, $ref)) $ref[$k] = []; $ref = &$ref[$k]; } $ref = $v;
    }

    /** Direct edits from the preview (tap-to-edit): whitelisted paths, no model call. */
    public function patch(object $website, object $s, array $patch): array
    {
        $draft = json_decode($s->draft_json, true) ?: self::emptyDraft($s->language);
        $n = 0;
        foreach ($patch as $path => $v) {
            if (!is_string($path) || !preg_match('/^(person\.(full_name|headline|email|phone|city|country|nationality|visa_status|availability)|summary|target\.roles|target\.countries|skills|languages|licences|references|education|experience|experience\.\d+\.(employer|title|city|start|end|bullets|raw)|education\.\d+\.(school|qualification|field|year)|meta\.template)$/', $path)) continue;
            if (in_array($path, ['experience', 'education', 'licences', 'languages', 'references', 'skills', 'target.roles', 'target.countries'], true) && !is_array($v)) continue;
            if (is_string($v)) $v = mb_substr(trim($v), 0, $path === 'summary' ? 800 : 200);
            if (is_array($v)) $v = self::trimArray($v, 0);
            if (preg_match('/\.(start|end)$/', $path)) $v = self::normMonth((string) $v);
            if ($path === 'meta.template' && !in_array($v, ['clean'], true)) continue;
            self::set($draft, $path, $v); $n++;
        }
        if (isset($draft['experience'])) $draft['experience'] = array_values(array_slice((array) $draft['experience'], 0, ResumeInterview::MAX_JOBS));
        DB::table('resume_sessions')->where('id', $s->id)->update(['draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'edit', 0, ['fields' => $n]);
        $s = DB::table('resume_sessions')->where('id', $s->id)->first();
        return ['success' => true, 'session' => $this->publicSession($s, $website)];
    }
    private static function trimArray($v, int $depth)
    {
        if ($depth > 3) return null;
        $out = []; $i = 0;
        foreach ((array) $v as $k => $x) { if ($i++ >= 40) break; $out[is_int($k) ? $k : mb_substr((string) $k, 0, 40)] = is_array($x) ? self::trimArray($x, $depth + 1) : (is_scalar($x) || $x === null ? (is_string($x) ? mb_substr(trim($x), 0, 300) : $x) : null); }
        return $out;
    }

    // ─── upload (enhance path + photo) ──────────────────────────────────────

    public function upload(object $website, object $s, UploadedFile $file, string $purpose = 'cv'): array
    {
        $bytes = $file->getSize(); if ($bytes > ResumeExtractor::MAX_BYTES) return ['success' => false, 'error' => 'TOO_LARGE'];
        $mime = (string) $file->getMimeType(); $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 8);
        $kind = null;
        if (str_starts_with($head, '%PDF')) $kind = 'pdf';
        elseif (str_starts_with($head, "PK\x03\x04") && preg_match('/wordprocessingml|msword|officedocument/i', $mime . ' ' . $file->getClientOriginalName())) $kind = 'docx';
        elseif (str_starts_with($head, "\xD0\xCF\x11\xE0")) $kind = 'docx';
        elseif (preg_match('/^image\/(jpeg|png|webp|heic|heif)$/', $mime) && (str_starts_with($head, "\xFF\xD8") || str_starts_with($head, "\x89PNG") || str_starts_with($head, 'RIFF') || str_contains($head, 'ftyp'))) $kind = 'image';
        if (!$kind) return ['success' => false, 'error' => 'UNSUPPORTED', 'message' => 'PDF, Word or a photo please.'];
        if ($purpose === 'photo' && $kind !== 'image') return ['success' => false, 'error' => 'UNSUPPORTED'];
        $dir = "resume/{$website->id}/{$s->id}"; $ext = $kind === 'pdf' ? 'pdf' : ($kind === 'docx' ? (str_starts_with($head, "PK") ? 'docx' : 'doc') : 'jpg');
        $name = ($purpose === 'photo' ? 'photo-' : 'cv-') . Str::random(8) . '.' . $ext;
        Storage::disk('local')->putFileAs($dir, $file, $name);
        $abs = Storage::disk('local')->path("{$dir}/{$name}");
        $draft = json_decode($s->draft_json, true) ?: self::emptyDraft($s->language);
        if ($purpose === 'photo') {
            $small = Storage::disk('local')->path("{$dir}/photo-small.jpg");
            shell_exec('/usr/bin/convert ' . escapeshellarg($abs) . ' -auto-orient -resize 600x720^ -gravity center -extent 600x720 -quality 82 ' . escapeshellarg($small) . ' 2>/dev/null');
            if (file_exists($small)) { $draft['person']['photo_path'] = "{$dir}/photo-small.jpg"; @unlink($abs); }
            $up = ['draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($s->state === 'photo') { $up['state'] = 'finish'; }
            DB::table('resume_sessions')->where('id', $s->id)->update($up);
            $this->event((int) $website->id, (int) $s->id, 'photo', 0);
            $s = DB::table('resume_sessions')->where('id', $s->id)->first();
            return $s->state === 'finish' ? $this->finish($website, $s) : ['success' => true, 'session' => $this->publicSession($s, $website)];
        }
        $uid = DB::table('resume_uploads')->insertGetId(['session_id' => $s->id, 'kind' => $kind, 'stored_path' => "{$dir}/{$name}", 'bytes' => $bytes, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $policy = $this->policy($website); $mode = $this->mode((int) $website->id, $policy);
        $allowVision = $mode === 'full' && (int) $s->vision_calls < self::MAX_VISION_CALLS;
        $ex = $this->extractor->extractText($abs, $kind, $allowVision);
        @unlink($abs); // the file is not kept; only the extracted text feeds the draft
        DB::table('resume_uploads')->where('id', $uid)->update(['stored_path' => null, 'extracted_chars' => mb_strlen($ex['text']), 'method' => $ex['method'], 'confidence' => $ex['confidence'], 'status' => $ex['ok'] ? 'extracted' : 'failed', 'error' => $ex['error'] ?? null, 'updated_at' => now()]);
        $cost = $ex['cost'];
        if (!$ex['ok']) { $this->event((int) $website->id, (int) $s->id, 'upload_failed', $cost, ['method' => $ex['method'], 'error' => $ex['error'] ?? null]); DB::table('resume_sessions')->where('id', $s->id)->increment('vision_calls', $ex['vision_calls']); return ['success' => false, 'error' => 'UNREADABLE', 'message' => ResumeInterview::ui($s->language)['upload_failed']]; }
        // structure it: rules first, then one model call (unless form mode)
        $pre = ResumeExtractor::preSplit($ex['text']);
        if ($pre['email'] && empty($draft['person']['email'])) $draft['person']['email'] = $pre['email'];
        if ($pre['phone'] && empty($draft['person']['phone'])) $draft['person']['phone'] = $pre['phone'];
        $calls = 0; $conf = [];
        if ($mode !== 'form' && (int) $s->model_calls < self::MAX_MODEL_CALLS) {
            $r = $this->writer->extract($ex['text']); $cost += $r['cost']; if (!$r['cached']) $calls++;
            if ($r['ok']) { $draft = self::mergeExtracted($draft, $r['data']); $conf = (array) ($r['data']['confidence'] ?? []); }
        } else {
            $draft['summary'] = $draft['summary'] ?: mb_substr($ex['text'], 0, 300); // form mode: the reader edits the text into fields
        }
        $draft['meta']['source'] = 'upload'; $draft['meta']['confidence'] = $conf;
        DB::table('resume_sessions')->where('id', $s->id)->update(['draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'state' => 'confirm', 'path' => 'upload', 'model_calls' => (int) $s->model_calls + $calls, 'vision_calls' => (int) $s->vision_calls + $ex['vision_calls'], 'cost_usd_micro' => (int) $s->cost_usd_micro + $cost, 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'upload_ok', $cost, ['method' => $ex['method'], 'chars' => mb_strlen($ex['text']), 'calls' => $calls, 'vision' => $ex['vision_calls']]);
        $s = DB::table('resume_sessions')->where('id', $s->id)->first();
        return ['success' => true, 'session' => $this->publicSession($s, $website)];
    }

    private static function mergeExtracted(array $draft, array $x): array
    {
        foreach (['full_name', 'headline', 'email', 'phone', 'city', 'country', 'nationality', 'visa_status'] as $k) if (!empty($x['person'][$k]) && is_scalar($x['person'][$k])) $draft['person'][$k] = mb_substr(trim((string) $x['person'][$k]), 0, 120);
        if (!empty($x['target']['roles']) && is_array($x['target']['roles'])) $draft['target']['roles'] = ResumeWriter::cleanList($x['target']['roles'], 4, 60);
        if (!empty($x['summary']) && is_string($x['summary'])) $draft['summary'] = mb_substr(trim($x['summary']), 0, 800);
        foreach (['experience', 'education', 'licences', 'languages'] as $k) if (!empty($x[$k]) && is_array($x[$k])) $draft[$k] = array_values(array_slice(array_filter(array_map(fn ($r) => is_array($r) ? self::trimArray($r, 1) : null, $x[$k])), 0, $k === 'experience' ? ResumeInterview::MAX_JOBS : 12));
        foreach ($draft['experience'] as &$j) { $j['start'] = self::normMonth((string) ($j['start'] ?? '')); $j['end'] = self::normMonth((string) ($j['end'] ?? '')); if (!isset($j['bullets'])) $j['bullets'] = []; } unset($j);
        if (!empty($x['skills']) && is_array($x['skills'])) $draft['skills'] = ResumeWriter::cleanList($x['skills'], 20, 40);
        return $draft;
    }

    /** Which fields are still missing after an upload (asked as gap questions). */
    public function gaps(array $draft): array
    {
        $g = [];
        if (empty($draft['person']['full_name'])) $g[] = 'name'; if (empty($draft['person']['phone'])) $g[] = 'phone'; if (empty($draft['target']['roles'])) $g[] = 'target_role';
        if (empty($draft['experience'])) $g[] = 'job_employer'; if (empty($draft['person']['visa_status'])) $g[] = 'visa_status'; if (empty($draft['person']['availability'])) $g[] = 'availability';
        return $g;
    }

    /** After the confirmation screen on the upload path: jump to the first gap question or finish. */
    public function confirmUpload(object $website, object $s, array $patch = []): array
    {
        if ($patch) $this->patch($website, $s, $patch); $s = DB::table('resume_sessions')->where('id', $s->id)->first();
        $draft = json_decode($s->draft_json, true) ?: []; $gaps = $this->gaps($draft);
        $prog = json_decode($s->progress_json, true) ?: []; $prog['gaps'] = $gaps; $prog['job_index'] = count((array) ($draft['experience'] ?? []));
        $next = $gaps ? $gaps[0] : 'finish';
        DB::table('resume_sessions')->where('id', $s->id)->update(['state' => $next, 'progress_json' => json_encode($prog), 'updated_at' => now()]);
        $s = DB::table('resume_sessions')->where('id', $s->id)->first();
        if ($next === 'finish') return $this->finish($website, $s);
        return ['success' => true, 'session' => $this->publicSession($s, $website)];
    }

    // ─── finish: the batched model pass (bullets per job + summary) ─────────

    public function finish(object $website, object $s): array
    {
        $draft = json_decode($s->draft_json, true) ?: self::emptyDraft($s->language);
        $policy = $this->policy($website); $mode = $this->mode((int) $website->id, $policy);
        $cost = 0; $calls = (int) $s->model_calls; $used = 0;
        foreach ((array) ($draft['experience'] ?? []) as $i => $j) {
            if (!empty($j['bullets'])) continue;
            if ($mode === 'full' && $calls + $used < self::MAX_MODEL_CALLS) { $r = $this->writer->bullets($j, $draft['target'] ?? []); $cost += $r['cost']; if (!$r['cached']) $used++; if ($r['ok'] && !empty($r['data']['bullets'])) { $draft['experience'][$i]['bullets'] = $r['data']['bullets']; continue; } }
            $draft['experience'][$i]['bullets'] = ResumeWriter::templatedBullets($j);
        }
        if (empty($draft['summary'])) {
            if ($mode !== 'form' && $calls + $used < self::MAX_MODEL_CALLS) { $r = $this->writer->summary($draft); $cost += $r['cost']; if (!$r['cached']) $used++; if ($r['ok'] && !empty($r['data']['summary'])) $draft['summary'] = $r['data']['summary']; }
            if (empty($draft['summary'])) $draft['summary'] = ResumeWriter::templatedSummary($draft);
        }
        if (empty($draft['person']['headline'])) $draft['person']['headline'] = implode(' · ', array_slice((array) ($draft['target']['roles'] ?? []), 0, 2));
        DB::table('resume_sessions')->where('id', $s->id)->update(['draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'state' => 'done', 'completed_at' => $s->completed_at ?: now(), 'model_calls' => $calls + $used, 'cost_usd_micro' => (int) $s->cost_usd_micro + $cost, 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'completed', $cost, ['mode' => $mode, 'calls' => $used, 'jobs' => count((array) ($draft['experience'] ?? []))]);
        if ($s->device_id) $this->ceilingHit('devcv:' . $s->device_id, self::DEVICE_CVS_PER_DAY); if ($s->ip) $this->ceilingHit('ipcv:' . $s->ip, self::IP_CVS_PER_DAY);
        $s = DB::table('resume_sessions')->where('id', $s->id)->first();
        return ['success' => true, 'session' => $this->publicSession($s, $website), 'mode' => $mode];
    }

    /** "Improve this" on one text; counts against the session's call cap. */
    public function improve(object $website, object $s, string $text, string $context = ''): array
    {
        $mode = $this->mode((int) $website->id, $this->policy($website));
        if ($mode === 'form') return ['success' => false, 'error' => 'ECONOMY', 'message' => ResumeInterview::ui($s->language)['economy']];
        if ((int) $s->model_calls >= self::MAX_MODEL_CALLS) return ['success' => false, 'error' => 'CALL_LIMIT'];
        $r = $this->writer->improve($text, $context);
        DB::table('resume_sessions')->where('id', $s->id)->update(['model_calls' => (int) $s->model_calls + ($r['cached'] ? 0 : 1), 'cost_usd_micro' => (int) $s->cost_usd_micro + $r['cost'], 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'improve', $r['cost']);
        return $r['ok'] ? ['success' => true, 'text' => $r['data']['text']] : ['success' => false, 'error' => 'MODEL_FAILED'];
    }

    // ─── output ─────────────────────────────────────────────────────────────

    public function html(object $s, bool $forPdf = false): string
    {
        $draft = json_decode($s->draft_json, true) ?: [];
        if (!empty($draft['person']['photo_path']) && Storage::disk('local')->exists($draft['person']['photo_path'])) $draft['person']['photo_data_uri'] = 'data:image/jpeg;base64,' . base64_encode(Storage::disk('local')->get($draft['person']['photo_path']));
        return ResumeRenderer::html($draft, $draft['meta']['template'] ?? 'clean', $forPdf);
    }

    /** Render (cached by content hash) and return the private path. */
    public function render(object $website, object $s): array
    {
        $draft = json_decode($s->draft_json, true) ?: []; $template = $draft['meta']['template'] ?? 'clean';
        $hash = hash('sha256', json_encode($draft) . '|' . $template . '|v1');
        $row = DB::table('resumes')->where('session_id', $s->id)->where('content_hash', $hash)->whereNotNull('pdf_path')->first();
        if ($row && Storage::disk('local')->exists($row->pdf_path)) return ['success' => true, 'resume_id' => (int) $row->id, 'path' => $row->pdf_path, 'cached' => true];
        $pdf = ResumeRenderer::pdf($draft + ['person' => ($draft['person'] ?? []) + (!empty($draft['person']['photo_path']) && Storage::disk('local')->exists($draft['person']['photo_path']) ? ['photo_data_uri' => 'data:image/jpeg;base64,' . base64_encode(Storage::disk('local')->get($draft['person']['photo_path']))] : [])], $template);
        $version = (int) DB::table('resumes')->where('session_id', $s->id)->max('version') + 1;
        $path = "resume/{$website->id}/{$s->id}/cv-v{$version}.pdf";
        Storage::disk('local')->put($path, $pdf);
        $id = DB::table('resumes')->insertGetId(['session_id' => $s->id, 'website_id' => $website->id, 'version' => $version, 'template' => $template, 'data_json' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'content_hash' => $hash, 'pdf_path' => $path, 'pdf_generated_at' => now(), 'word_count' => str_word_count(strip_tags(ResumeRenderer::html($draft))), 'created_at' => now(), 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'render', 0, ['version' => $version]);
        return ['success' => true, 'resume_id' => $id, 'path' => $path, 'cached' => false];
    }

    public function filename(object $s): string
    {
        $draft = json_decode($s->draft_json, true) ?: []; $n = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($draft['person']['full_name'] ?? 'CV'));
        return trim($n, '-') !== '' ? 'CV-' . trim($n, '-') . '.pdf' : 'CV.pdf';
    }

    public function saveEmail(object $website, object $s, string $email): array
    {
        $email = strtolower(trim($email)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'INVALID_EMAIL'];
        DB::table('resume_sessions')->where('id', $s->id)->update(['email' => $email, 'expires_at' => now()->addDays(self::SAVED_TTL_DAYS), 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'email_saved', 0);
        return ['success' => true];
    }

    public function delete(object $website, object $s): array
    {
        try { Storage::disk('local')->deleteDirectory("resume/{$website->id}/{$s->id}"); } catch (\Throwable) {}
        DB::table('resumes')->where('session_id', $s->id)->delete(); DB::table('resume_uploads')->where('session_id', $s->id)->delete();
        DB::table('resume_sessions')->where('id', $s->id)->update(['draft_json' => null, 'email' => null, 'device_id' => null, 'ip' => null, 'user_agent' => null, 'token_hash' => hash('sha256', 'deleted-' . $s->id . '-' . Str::random(16)), 'deleted_at' => now(), 'updated_at' => now()]);
        $this->event((int) $website->id, (int) $s->id, 'deleted', 0);
        return ['success' => true];
    }

    /** Daily purge: expired sessions lose all PII and files; events keep only counts. */
    public function purge(): array
    {
        $n = 0;
        foreach (DB::table('resume_sessions')->whereNull('deleted_at')->where('expires_at', '<', now())->limit(500)->get(['id', 'website_id']) as $s) {
            try { Storage::disk('local')->deleteDirectory("resume/{$s->website_id}/{$s->id}"); } catch (\Throwable) {}
            DB::table('resumes')->where('session_id', $s->id)->delete(); DB::table('resume_uploads')->where('session_id', $s->id)->delete();
            DB::table('resume_sessions')->where('id', $s->id)->update(['draft_json' => null, 'email' => null, 'device_id' => null, 'ip' => null, 'user_agent' => null, 'deleted_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        DB::table('resume_events')->where('created_at', '<', now()->subDays(400))->delete();
        DB::table('resume_cache')->where('created_at', '<', now()->subDays(90))->where('hits', 0)->delete();
        return ['purged' => $n];
    }

    /** Desk-facing analytics. */
    public function stats(int $wid): array
    {
        $since = now()->subDays(30);
        $ev = DB::table('resume_events')->where('website_id', $wid)->where('created_at', '>=', $since)->select('event', DB::raw('count(*) c'), DB::raw('sum(cost_usd_micro) usd'))->groupBy('event')->get()->keyBy('event');
        $today = (int) DB::table('resume_events')->where('website_id', $wid)->where('created_at', '>=', now()->startOfDay())->sum('cost_usd_micro');
        $month = (int) DB::table('resume_events')->where('website_id', $wid)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd_micro');
        $completed = (int) ($ev['completed']->c ?? 0); $started = (int) ($ev['started']->c ?? 0);
        $days = DB::table('resume_events')->where('website_id', $wid)->where('created_at', '>=', $since)->whereIn('event', ['started', 'completed', 'render'])->select(DB::raw('date(created_at) d'), 'event', DB::raw('count(*) c'))->groupBy('d', 'event')->get();
        $series = []; foreach ($days as $r) $series[$r->d][$r->event] = (int) $r->c;
        return ['started_30d' => $started, 'completed_30d' => $completed, 'completion_rate' => $started ? round($completed / $started * 100) : 0, 'downloads_30d' => (int) ($ev['download']->c ?? 0), 'emails_30d' => (int) ($ev['email_sent']->c ?? 0),
            'uploads_ok_30d' => (int) ($ev['upload_ok']->c ?? 0), 'uploads_failed_30d' => (int) ($ev['upload_failed']->c ?? 0), 'cost_today_usd' => round($today / 1e6, 4), 'cost_month_usd' => round($month / 1e6, 4),
            'cost_per_cv_usd' => $completed ? round(((int) $ev->sum('usd')) / 1e6 / $completed, 4) : 0, 'series' => $series, 'active_sessions' => DB::table('resume_sessions')->where('website_id', $wid)->whereNull('deleted_at')->where('last_seen_at', '>=', now()->subDay())->count()];
    }
}
