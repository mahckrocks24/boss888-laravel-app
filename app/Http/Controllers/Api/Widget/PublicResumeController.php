<?php

namespace App\Http\Controllers\Api\Widget;

use App\Engines\Chatbot\Services\ChatbotWidgetTokenService;
use App\Engines\Resume\Services\ResumeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * RESUME888 — public, no-login API on the chatbot rails: X-CHATBOT-TOKEN (label 'resume') + Origin allow-list,
 * honeypot, per-IP/per-session Redis limits, visitor session token in X-RESUME-SESSION. All errors are generic.
 */
class PublicResumeController
{
    public function __construct(private ChatbotWidgetTokenService $tokens, private ResumeService $resume) {}

    private function auth(Request $r): array
    {
        $plain = (string) $r->header('X-CHATBOT-TOKEN'); if ($plain === '') return [null, null, $this->err('TOKEN_MISSING', 401)];
        $row = $this->tokens->verify($plain); if (!$row) return [null, null, $this->err('TOKEN_INVALID', 401)];
        if (!$this->tokens->originAllowed($row, $r->header('Origin'), $r->header('Referer'))) return [null, null, $this->err('DOMAIN_NOT_ALLOWED', 403)];
        $wid = (int) ($r->attributes->get('published_website_id') ?: $row->website_id ?: 0);
        $website = $wid ? DB::table('websites')->where('id', $wid)->where('workspace_id', (int) $row->workspace_id)->whereNull('deleted_at')->first() : null;
        if (!$website) return [null, null, $this->err('SITE_NOT_FOUND', 404)];
        return [$row, $website, null];
    }
    private function session(Request $r, object $website): array
    {
        $s = $this->resume->find($website, (string) $r->header('X-RESUME-SESSION'));
        return $s ? [$s, null] : [null, $this->err('SESSION_INVALID', 401)];
    }
    private function err(string $code, int $status, ?string $msg = null): JsonResponse { return response()->json(array_filter(['success' => false, 'error' => $code, 'message' => $msg]), $status); }
    private function limited(Request $r, string $key, int $max, int $secs = 60): bool { $k = $key . ':' . $r->ip(); if (RateLimiter::tooManyAttempts($k, $max)) return true; RateLimiter::hit($k, $secs); return false; }
    private function out(array $res): JsonResponse
    {
        $code = 200; if (empty($res['success'])) $code = match ($res['error'] ?? '') { 'DAILY_LIMIT', 'TURN_LIMIT', 'CALL_LIMIT' => 429, 'SESSION_INVALID' => 401, 'DISABLED' => 404, 'TOO_LARGE' => 413, 'UNSUPPORTED', 'UNREADABLE', 'REQUIRED', 'INVALID_EMAIL', 'STEP_MISMATCH', 'COMPLETED' => 422, default => 400 };
        return response()->json($res, $code);
    }
    private function guard(callable $fn): \Symfony\Component\HttpFoundation\Response
    {
        try { return $fn(); }
        catch (\Illuminate\Validation\ValidationException $e) { return response()->json(['success' => false, 'error' => 'VALIDATION', 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422); }
        catch (\Throwable $e) { Log::error('resume.failed', ['e' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]); return $this->err('INTERNAL', 500, 'Something went wrong. Please try again.'); }
    }
    private function honeypot(Request $r): bool { return trim((string) $r->input('hp', '')) !== ''; }

    public function config(Request $r): JsonResponse
    {
        [$tok, $website, $e] = $this->auth($r); if ($e) return $e;
        $p = $this->resume->policy($website);
        return response()->json(['success' => true, 'enabled' => $p['enabled'], 'languages' => ['tl' => 'Taglish', 'en' => 'English', 'fil' => 'Filipino'], 'ui' => ['tl' => \App\Engines\Resume\Services\ResumeInterview::ui('tl'), 'en' => \App\Engines\Resume\Services\ResumeInterview::ui('en'), 'fil' => \App\Engines\Resume\Services\ResumeInterview::ui('fil')]]);
    }

    public function start(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            [$tok, $website, $e] = $this->auth($r); if ($e) return $e;
            if ($this->honeypot($r)) return $this->err('BAD_REQUEST', 400);
            if ($this->limited($r, 'rs:start', 20)) return $this->err('RATE_LIMITED', 429);
            $d = $r->validate(['language' => 'nullable|string|in:tl,en,fil', 'device_id' => 'nullable|string|max:64', 'consent' => 'required|accepted']);
            $res = $this->resume->start($website, $d, (string) $r->ip(), $r->userAgent());
            return $this->out($res);
        });
    }

    public function show(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) { [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2; return response()->json(['success' => true, 'session' => $this->resume->publicSession($s, $website)]); });
    }

    public function answer(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2;
            if ($this->honeypot($r)) return $this->err('BAD_REQUEST', 400);
            if ($this->limited($r, 'rs:turn', 40)) return $this->err('RATE_LIMITED', 429);
            $step = (string) $r->input('step', ''); if (!preg_match('/^[a-z_]{2,32}$/', $step)) return $this->err('BAD_REQUEST', 400);
            $ans = $r->input('answer'); if (is_string($ans) && strlen($ans) > 4000) return $this->err('TOO_LONG', 422);
            if ($step === 'confirm') return $this->out($this->resume->confirmUpload($website, $s, is_array($r->input('patch')) ? $r->input('patch') : []));
            return $this->out($this->resume->answer($website, $s, $step, $ans, (bool) $r->boolean('skip')));
        });
    }

    public function patch(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) { [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2; if ($this->limited($r, 'rs:patch', 60)) return $this->err('RATE_LIMITED', 429); $p = $r->input('patch'); if (!is_array($p) || count($p) > 40) return $this->err('BAD_REQUEST', 400); return $this->out($this->resume->patch($website, $s, $p)); });
    }

    public function upload(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2;
            if ($this->limited($r, 'rs:upload', 5)) return $this->err('RATE_LIMITED', 429);
            $f = $r->file('file'); if (!$f || !$f->isValid()) return $this->err('NO_FILE', 422);
            $purpose = $r->input('purpose') === 'photo' ? 'photo' : 'cv';
            return $this->out($this->resume->upload($website, $s, $f, $purpose));
        });
    }

    public function finish(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) { [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2; if ($this->limited($r, 'rs:finish', 10)) return $this->err('RATE_LIMITED', 429); return $this->out($this->resume->finish($website, $s)); });
    }

    public function improve(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) { [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2; if ($this->limited($r, 'rs:improve', 10)) return $this->err('RATE_LIMITED', 429); $d = $r->validate(['text' => 'required|string|max:800', 'context' => 'nullable|string|max:200']); return $this->out($this->resume->improve($website, $s, $d['text'], (string) ($d['context'] ?? ''))); });
    }

    public function preview(Request $r)
    {
        return $this->guard(function () use ($r) { [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2; return response($this->resume->html($s, false), 200)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex'); });
    }

    public function render(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2;
            if ($this->limited($r, 'rs:render', 10, 3600)) return $this->err('RATE_LIMITED', 429);
            $res = $this->resume->render($website, $s); if (empty($res['success'])) return $this->out($res);
            // relative signatures: the link is served on the SITE host, which differs from the platform APP_URL
            $url = $r->getSchemeAndHttpHost() . URL::temporarySignedRoute('resume.download', now()->addMinutes(30), ['resume' => $res['resume_id']], false);
            DB::table('resume_events')->insert(['website_id' => $website->id, 'session_id' => $s->id, 'event' => 'download', 'cost_usd_micro' => 0, 'created_at' => now()]);
            return response()->json(['success' => true, 'url' => $url, 'filename' => $this->resume->filename($s)]);
        });
    }

    /** GET /resume-download/{resume}?signature=… (web route on the site host). */
    public function download(Request $r, int $resume)
    {
        if (!$r->hasValidRelativeSignature()) abort(403);
        $row = DB::table('resumes')->where('id', $resume)->first(); if (!$row || !$row->pdf_path || !Storage::disk('local')->exists($row->pdf_path)) abort(404);
        $s = DB::table('resume_sessions')->where('id', $row->session_id)->first(); $name = $s ? $this->resume->filename($s) : 'CV.pdf';
        return response(Storage::disk('local')->get($row->pdf_path), 200)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="' . $name . '"')->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function email(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2;
            if ($this->limited($r, 'rs:email', 3, 3600)) return $this->err('RATE_LIMITED', 429);
            $d = $r->validate(['email' => 'required|email|max:190']);
            $saved = $this->resume->saveEmail($website, $s, $d['email']); if (empty($saved['success'])) return $this->out($saved);
            $rendered = $this->resume->render($website, $s); if (empty($rendered['success'])) return $this->out($rendered);
            $host = $r->getSchemeAndHttpHost();
            $link = $host . URL::temporarySignedRoute('resume.resume', now()->addDays(ResumeService::SAVED_TTL_DAYS), ['session' => $s->id, 'k' => substr(hash('sha256', $s->token_hash . '|' . $s->id), 0, 24)], false);
            $dl = $host . URL::temporarySignedRoute('resume.download', now()->addDays(7), ['resume' => $rendered['resume_id']], false);
            try {
                Mail::to($d['email'])->send(new \App\Engines\Resume\Mail\ResumeReadyMail((string) $website->name, $dl, $link, $s->language));
            } catch (\Throwable $ex) { Log::warning('resume.mail.failed', ['e' => $ex->getMessage()]); return $this->err('MAIL_FAILED', 502, 'Could not send the email right now.'); }
            DB::table('resume_events')->insert(['website_id' => $website->id, 'session_id' => $s->id, 'event' => 'email_sent', 'cost_usd_micro' => 0, 'created_at' => now()]);
            return response()->json(['success' => true]);
        });
    }

    /** GET /resume/continue/{session}?k=…&signature=… — magic link: hands the visitor token back to the browser via a one-time page. */
    public function continueLink(Request $r, int $session)
    {
        if (!$r->hasValidRelativeSignature()) abort(403);
        $s = DB::table('resume_sessions')->where('id', $session)->whereNull('deleted_at')->first(); if (!$s) abort(404);
        if ((string) $r->query('k') !== substr(hash('sha256', $s->token_hash . '|' . $s->id), 0, 24)) abort(403);
        // rotate the visitor token on every magic-link use (the old one may be on a lost phone)
        $plain = 'rs_' . bin2hex(random_bytes(24));
        DB::table('resume_sessions')->where('id', $s->id)->update(['token_hash' => hash('sha256', $plain), 'last_seen_at' => now(), 'updated_at' => now()]);
        $wid = (int) $s->website_id;
        $html = '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Opening your CV…</title><script nonce="x">try{localStorage.setItem("rs_token_' . $wid . '",' . json_encode($plain) . ');}catch(e){}location.replace("/jobs/resume");</script><p>Opening your CV…</p>';
        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function destroy(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) { [$tok, $website, $e] = $this->auth($r); if ($e) return $e; [$s, $e2] = $this->session($r, $website); if ($e2) return $e2; return $this->out($this->resume->delete($website, $s)); });
    }
}
