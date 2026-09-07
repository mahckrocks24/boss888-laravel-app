<?php

namespace Tests\Feature\Resume888;

use App\Engines\Chatbot\Services\ChatbotWidgetTokenService;
use App\Engines\Resume\Services\ResumeExtractor;
use App\Engines\Resume\Services\ResumeRenderer;
use App\Engines\Resume\Services\ResumeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RESUME888 Unit 1 — end-to-end in FORM MODE (budget 0 → no model calls, no network): session, templated
 * interview, patch, finish, render PDF, extraction round-trip (PDF → pdftotext), delete, purge, reserved page.
 */
class ResumeBuilderTest extends TestCase
{
    private int $ws = 999999901; private int $site = 999999906; private int $user = 0; private string $token = '';

    protected function setUp(): void
    {
        parent::setUp(); $this->cleanup();
        $this->user = (int) DB::table('users')->insertGetId(['name' => 'Resume QA', 'email' => 'resume-qa-' . $this->ws . '@example.test', 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Resume QA', 'slug' => 'resume-qa-' . $this->ws, 'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['id' => $this->site, 'workspace_id' => $this->ws, 'name' => 'Resume QA News', 'subdomain' => 'resumeqa.levelupgrowth.io', 'status' => 'published', 'type' => 'builder',
            'settings_json' => json_encode(['theme' => 'kabayan-news', 'regions' => [['code' => 'AE'], ['code' => 'QA']], 'resume' => ['daily_budget_usd' => 0, 'monthly_budget_usd' => 0]]), 'created_at' => now(), 'updated_at' => now()]);
        $m = app(ChatbotWidgetTokenService::class)->mint($this->ws, null, $this->site, ['resumeqa.levelupgrowth.io'], 'resume');
        $this->token = $m['token'] ?? $m['plain'] ?? (string) reset($m);
        DB::table('pages')->insert(['website_id' => $this->site, 'title' => 'CV', 'slug' => 'resume', 'type' => 'page', 'status' => 'published', 'is_homepage' => 0, 'position' => 5, 'sections_json' => json_encode(['sections' => [['type' => 'header', 'nav_links' => [['label' => 'Jobs', 'url' => '/jobs']]], ['type' => 'resume_builder', 'mode' => 'full'], ['type' => 'footer']]]), 'created_at' => now(), 'updated_at' => now()]);
    }
    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }
    private function cleanup(): void
    {
        foreach (DB::table('resume_sessions')->where('website_id', $this->site)->pluck('id') as $sid) { DB::table('resumes')->where('session_id', $sid)->delete(); DB::table('resume_uploads')->where('session_id', $sid)->delete(); }
        DB::table('resume_events')->where('website_id', $this->site)->delete(); DB::table('resume_sessions')->where('website_id', $this->site)->delete();
        DB::table('chatbot_widget_tokens')->where('website_id', $this->site)->delete(); DB::table('pages')->where('website_id', $this->site)->delete();
        DB::table('websites')->where('id', $this->site)->delete(); DB::table('workspaces')->where('id', $this->ws)->delete(); DB::table('users')->where('email', 'resume-qa-' . $this->ws . '@example.test')->delete();
        try { Storage::disk('local')->deleteDirectory('resume/' . $this->site); } catch (\Throwable) {}
    }
    private function h(?string $session = null): array { $h = ['X-CHATBOT-TOKEN' => $this->token, 'Origin' => 'https://resumeqa.levelupgrowth.io', 'Accept' => 'application/json']; if ($session) $h['X-RESUME-SESSION'] = $session; return $h; }
    private function api(string $method, string $path, array $data = [], ?string $session = null) { return $this->withHeaders($this->h($session))->json($method, '/api/public/resume' . $path, $data); }
    private function answer(string $tok, string $step, $answer, bool $skip = false) { $r = $this->api('POST', '/answer', ['step' => $step, 'answer' => $answer, 'skip' => $skip], $tok); $r->assertStatus(200, "step {$step}: " . $r->getContent()); return $r; }

    public function test_full_interview_in_form_mode_renders_a_pdf_with_no_model_calls(): void
    {
        $this->api('POST', '/session/start', ['language' => 'tl'])->assertStatus(422); // consent required
        $this->withHeaders(['Origin' => 'https://evil.example', 'X-CHATBOT-TOKEN' => $this->token, 'Accept' => 'application/json'])->postJson('/api/public/resume/session/start', ['consent' => true])->assertStatus(403);
        $s = $this->api('POST', '/session/start', ['language' => 'tl', 'consent' => true, 'device_id' => 'dev-qa-1']); $s->assertStatus(200)->assertJsonPath('session.state', 'path')->assertJsonPath('session.mode', 'form');
        $tok = $s->json('token'); $this->assertMatchesRegularExpression('/^rs_[a-f0-9]{48}$/', $tok);
        $this->assertStringContainsString('May CV ka na ba', $s->json('session.step.q'));
        $this->answer($tok, 'path', 'interview')->assertJsonPath('session.state', 'region');
        $this->answer($tok, 'region', 'QA')->assertJsonPath('session.step.id', 'name');
        $this->answer($tok, 'name', 'Maria Clara Santos');
        $this->answer($tok, 'phone', '+974 3312 3456');
        $this->answer($tok, 'email', '', true); // skippable
        $this->answer($tok, 'city', ['person.city' => 'Doha', 'person.country' => 'QA']);
        $this->answer($tok, 'target_role', 'Barista, Cashier');
        $this->answer($tok, 'target_countries', ['QA', 'AE']);
        $this->answer($tok, 'job_employer', 'Kape Doha LLC');
        $this->answer($tok, 'job_title', 'Barista');
        $this->answer($tok, 'job_dates', ['experience.0.city' => 'Doha, Qatar', 'experience.0.start' => 'March 2022', 'experience.0.end' => 'present']);
        $this->answer($tok, 'job_desc', 'Nagseserve ng customers araw-araw, naghahandle ng cash at POS, nagbubukas ng store, nagtetrain ng dalawang bagong staff.');
        $this->answer($tok, 'job_proud', 'Employee of the month, twice')->assertJsonPath('session.step.id', 'job_more');
        $this->answer($tok, 'job_more', 'yes')->assertJsonPath('session.step.id', 'job_employer');
        $this->answer($tok, 'job_employer', 'SM Supermalls'); $this->answer($tok, 'job_title', 'Sales Associate');
        $this->answer($tok, 'job_dates', ['experience.1.start' => '06 2019', 'experience.1.end' => '2021']);
        $this->answer($tok, 'job_desc', 'Sales at customer service'); $this->answer($tok, 'job_proud', '', true);
        $this->answer($tok, 'job_more', 'no')->assertJsonPath('session.step.id', 'education');
        $this->answer($tok, 'education', ['education.0.qualification' => 'BS Hotel and Restaurant Management', 'education.0.school' => 'University of Cebu', 'education.0.year' => '2018']);
        $this->answer($tok, 'licences', ['TESDA NC II', 'NBI clearance', 'Food safety / PIC']);
        $this->answer($tok, 'skills', 'customer service, cash handling, POS, MS Excel');
        $this->answer($tok, 'languages', ['English', 'Filipino', 'Arabic (basic)']);
        $this->answer($tok, 'visa_status', 'Employment visa (transferable)');
        $done = $this->answer($tok, 'availability', 'Within 30 days');
        // photo is skippable → finish runs (form mode: templated bullets + summary, 0 model calls)
        $done = $this->answer($tok, 'photo', null, true);
        $done->assertJsonPath('session.state', 'done')->assertJsonPath('session.completed', true)->assertJsonPath('mode', 'form');
        $d = $done->json('session.draft');
        $this->assertSame('Maria Clara Santos', $d['person']['full_name']); $this->assertSame('2022-03', $d['experience'][0]['start']); $this->assertSame('present', $d['experience'][0]['end']); $this->assertSame('2019-06', $d['experience'][1]['start']); $this->assertSame('2021-01', $d['experience'][1]['end']);
        $this->assertNotEmpty($d['experience'][0]['bullets']); $this->assertNotEmpty($d['summary']); $this->assertSame(['Barista', 'Cashier'], $d['target']['roles']);
        $row = DB::table('resume_sessions')->where('id', $done->json('session.id'))->first();
        $this->assertSame(0, (int) $row->model_calls); $this->assertSame(0, (int) $row->cost_usd_micro);
        // tap-to-edit patch + template guard
        $this->api('POST', '/patch', ['patch' => ['summary' => 'Experienced barista and cashier.', 'experience.0.bullets' => ['Served 200+ customers a day', 'Handled cash and POS'], 'meta.template' => 'evil', 'person.headline' => '<b>x</b>']], $tok)->assertStatus(200)->assertJsonPath('session.draft.summary', 'Experienced barista and cashier.')->assertJsonPath('session.draft.meta.template', 'clean');
        // preview + render + signed download on the site host (HTML in fields is escaped, never rendered)
        $this->withHeaders($this->h($tok))->get('/api/public/resume/preview')->assertStatus(200)->assertSee('Maria Clara Santos')->assertSee('Handled cash and POS')->assertSee('&lt;b&gt;x&lt;/b&gt;', false)->assertDontSee('<b>x</b>', false);
        $r = $this->api('POST', '/render', [], $tok); $r->assertStatus(200); $this->assertStringContainsString('/resume-download/', $r->json('url')); $this->assertSame('CV-Maria-Clara-Santos.pdf', $r->json('filename'));
        $path = parse_url($r->json('url'), PHP_URL_PATH) . '?' . parse_url($r->json('url'), PHP_URL_QUERY);
        $dl = $this->get('https://resumeqa.levelupgrowth.io' . $path); $dl->assertStatus(200); $this->assertStringStartsWith('%PDF', $dl->getContent()); $this->assertStringContainsString('attachment; filename="CV-Maria-Clara-Santos.pdf"', (string) $dl->headers->get('Content-Disposition'));
        $this->assertSame(1, DB::table('resumes')->where('session_id', $row->id)->count());
        $this->api('POST', '/render', [], $tok)->assertStatus(200); $this->assertSame(1, DB::table('resumes')->where('session_id', $row->id)->count(), 'unchanged content → cached PDF, no second render');
        $this->get('https://resumeqa.levelupgrobwth.io' . preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $path))->assertStatus(403);
        // delete erases everything
        $this->api('DELETE', '/session', [], $tok)->assertStatus(200);
        $this->api('GET', '/session', [], $tok)->assertStatus(401);
        $this->assertNull(DB::table('resume_sessions')->where('id', $row->id)->value('draft_json'));
    }

    public function test_upload_path_extracts_a_text_pdf_and_jumps_to_gap_questions(): void
    {
        $s = $this->api('POST', '/session/start', ['language' => 'en', 'consent' => true]); $tok = $s->json('token');
        $this->answer($tok, 'path', 'upload')->assertJsonPath('session.state', 'region');
        $this->answer($tok, 'region', 'AE')->assertJsonPath('session.step.id', 'upload');
        $pdf = ResumeRenderer::pdf(['person' => ['full_name' => 'Juan Dela Cruz', 'email' => 'juan@example.test', 'phone' => '+971 50 123 4567', 'city' => 'Dubai', 'country' => 'AE'], 'target' => ['roles' => ['Driver']], 'summary' => 'Professional driver with five years in Dubai.', 'experience' => [['employer' => 'Fleet Co', 'title' => 'Driver', 'start' => '2020-01', 'end' => 'present', 'bullets' => ['Drove delivery routes across Dubai daily']]], 'education' => [], 'licences' => [['name' => 'UAE driving licence']], 'skills' => ['defensive driving'], 'languages' => [['name' => 'English']]]);
        $file = UploadedFile::fake()->createWithContent('old-cv.pdf', $pdf);
        $up = $this->withHeaders($this->h($tok))->post('/api/public/resume/upload', ['file' => $file]);
        $up->assertStatus(200)->assertJsonPath('session.state', 'confirm');
        $d = $up->json('session.draft');
        $this->assertSame('juan@example.test', $d['person']['email'], 'rule-based contact extraction (no model in form mode)');
        $this->assertStringContainsString('+971 50 123 4567', $d['person']['phone']);
        $row = DB::table('resume_uploads')->where('session_id', $up->json('session.id'))->first(); $this->assertSame('pdftotext', $row->method); $this->assertSame('extracted', $row->status); $this->assertNull($row->stored_path, 'the upload itself is not kept');
        // confirm with corrections → first gap question (form mode leaves name empty → 'name')
        $c = $this->api('POST', '/answer', ['step' => 'confirm', 'patch' => ['person.full_name' => 'Juan Dela Cruz']], $tok); $c->assertStatus(200);
        $this->assertContains($c->json('session.state'), ['target_role', 'job_employer', 'visa_status', 'availability', 'finish', 'done']);
        $bad = $this->withHeaders($this->h($tok))->post('/api/public/resume/upload', ['file' => UploadedFile::fake()->createWithContent('x.exe', "MZ\x90\x00garbage")]); $bad->assertStatus(422)->assertJsonPath('error', 'UNSUPPORTED');
    }

    public function test_daily_ceilings_purge_and_reserved_page(): void
    {
        for ($i = 0; $i < ResumeService::DEVICE_SESSIONS_PER_DAY; $i++) $this->api('POST', '/session/start', ['consent' => true, 'device_id' => 'dev-cap-' . $this->ws])->assertStatus(200);
        $this->api('POST', '/session/start', ['consent' => true, 'device_id' => 'dev-cap-' . $this->ws])->assertStatus(429)->assertJsonPath('error', 'DAILY_LIMIT');
        \Illuminate\Support\Facades\RateLimiter::clear('rs:dev:dev-cap-' . $this->ws . ':' . now()->format('Ymd'));
        // purge: expire a session, run the sweep, PII gone
        $sid = (int) DB::table('resume_sessions')->where('website_id', $this->site)->orderBy('id')->value('id');
        DB::table('resume_sessions')->where('id', $sid)->update(['expires_at' => now()->subDay()]);
        $this->artisan('resume:purge')->assertExitCode(0);
        $this->assertNull(DB::table('resume_sessions')->where('id', $sid)->value('draft_json')); $this->assertNotNull(DB::table('resume_sessions')->where('id', $sid)->value('deleted_at'));
        // /jobs/resume is a page, not a listing
        $p = $this->get('https://resumeqa.levelupgrowth.io/jobs/resume'); $p->assertStatus(200); $p->assertHeader('X-Served-By', 'reserved-page'); $this->assertStringContainsString('id="kb-resume"', $p->getContent()); $this->assertStringContainsString('/resume/resume.js', $p->getContent());
        // extractor helpers
        $this->assertSame('2023-11', ResumeService::normMonth('Nov 2023')); $this->assertSame('present', ResumeService::normMonth('hanggang ngayon')); $this->assertSame('2021-06', ResumeService::normMonth('6/2021'));
        $pre = ResumeExtractor::preSplit("JUAN DELA CRUZ\njuan@example.test | +971 50 123 4567\n\nEXPERIENCE\nDriver at Fleet Co\n\nEDUCATION\nBS Something"); $this->assertSame('juan@example.test', $pre['email']); $this->assertGreaterThanOrEqual(2, count($pre['sections']));
    }
}
