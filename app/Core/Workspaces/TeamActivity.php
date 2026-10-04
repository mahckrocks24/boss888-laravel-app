<?php

namespace App\Core\Workspaces;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TEAM-ACTIVITY-1 (Owner 2026-10-04: "owner(user) should see the activities of human team members").
 *
 * Every change a signed-in person makes through the app is written to audit_logs as `activity.<area>` with their
 * user id and one plain sentence ("Asked Arthur: 'make the headline shorter' on Chef Red"). Reads, sign-ins, background
 * calls and failed requests are not activity. Credits a person spends are attributed through CreditService (the
 * reservation carries acting_user_id; tasks carry _acting_user_id into the queue). The owner and admins read it in
 * Settings -> Team members -> Activity.
 */
final class TeamActivity
{
    /** paths (after api/) that are never activity */
    private const SKIP = '~^(auth/|internal/|webhook|webhooks/|public/|invite/|user/preferences|devices/|messages/[^/]+/read|notifications|workspace/agents/positions|tracking|telemetry|heartbeat|presence|ui/|sarah-intro|intro/|tour|chat-meter|team/activity|.*/preview$|.*/estimate$|.*/search$|.*/suggest(ions)?$|.*/poll$|.*/status$|templates/preview|growth/watch/ping)~i';

    public static function bindActor(Request $r): void
    {
        try { $u = $r->user(); if ($u && $u->id) app()->instance('lu.acting_user_id', (int) $u->id); } catch (\Throwable $e) {}
    }

    public static function actor(): int
    {
        try { return app()->bound('lu.acting_user_id') ? (int) app('lu.acting_user_id') : 0; } catch (\Throwable $e) { return 0; }
    }

    /** chat routes send their reply early and keep working (Sarah two-phase): their activity is written on the way in */
    public const EARLY = '~^agents/[a-z0-9_-]+/messages$~';

    public static function recordEarly(Request $r): void
    {
        try { $path = ltrim(preg_replace('~^/?api/~', '', '/' . ltrim($r->path(), '/')), '/'); if ($r->isMethod('POST') && preg_match(self::EARLY, $path)) { $r->attributes->set('ta_done', true); self::record($r, null, true); } } catch (Throwable $e) {}
    }

    public static function record(Request $r, $response, bool $early = false): void
    {
        if (! $early && $r->attributes->get('ta_done')) return;
        try {
            $m = strtoupper($r->method());
            if (! in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
            $status = ($response && method_exists($response, 'getStatusCode')) ? (int) $response->getStatusCode() : 200;
            if ($status >= 400) return;
            $u = $r->user(); if (! $u) return;
            $ws = (int) ($r->attributes->get('workspace_id') ?? 0); if ($ws <= 0) return;
            $path = ltrim(preg_replace('~^/?api/~', '', '/' . ltrim($r->path(), '/')), '/');
            if ($path === '' || preg_match(self::SKIP, $path)) return;
            [$area, $text, $etype, $eid] = self::describe($m, $path, $r, $response);
            if ($text === null) return;
            DB::table('audit_logs')->insert([
                'workspace_id' => $ws, 'user_id' => (int) $u->id, 'action' => 'activity.' . $area,
                'entity_type' => $etype, 'entity_id' => $eid,
                'metadata_json' => json_encode(['text' => mb_substr($text, 0, 300), 'method' => $m, 'path' => mb_substr($path, 0, 160)], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { Log::debug('[TEAM-ACTIVITY-1] not recorded: ' . $e->getMessage()); }
    }

    private static function q(?string $s, int $n = 90): string
    {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s)); if ($s === '') return '';
        return '“' . (mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s) . '”';
    }
    private static function site($id): string { $n = $id ? DB::table('websites')->where('id', (int) $id)->value('name') : null; return $n ? (string) $n : 'a website'; }
    private static function pageSite($pid): array { $p = DB::table('pages')->where('id', (int) $pid)->first(['website_id']); return $p ? [(int) $p->website_id, self::site($p->website_id)] : [null, 'a website']; }
    private static function person($id): string { $u = DB::table('users')->where('id', (int) $id)->first(['name', 'email']); return $u ? (string) ($u->name ?: $u->email) : 'a team member'; }
    private static function plan($id): string { $n = $id ? DB::table('plans')->where('id', (int) $id)->value('name') : null; return $n ? (string) $n : 'another plan'; }
    private static function title(string $table, $id, string $col = 'title'): string { try { $t = DB::table($table)->where('id', (int) $id)->value($col); return $t ? self::q($t, 70) : ''; } catch (\Throwable $e) { return ''; } }
    private static function agent(string $slug): string { $n = DB::table('agents')->where('slug', $slug)->value('name'); return $n ? (string) $n : ucfirst($slug); }

    /** @return array{0:string,1:?string,2:?string,3:?int} area, sentence (null = not activity), entity type, entity id */
    private static function describe(string $m, string $p, Request $r, $resp): array
    {
        $in = fn ($k) => $r->input($k);
        $seg = explode('/', $p);
        $id = null; foreach ($seg as $s) { if (ctype_digit($s)) { $id = (int) $s; break; } }

        // Sarah and the team
        if (preg_match('~^agents/([a-z0-9_-]+)/messages$~', $p, $x)) return ['chat', 'Messaged ' . self::agent($x[1]) . ' ' . self::q($in('content') ?? $in('message')), 'Agent', null];
        if (preg_match('~^studio/ai/chat$~', $p)) return ['studio', 'Chatted with the Studio assistant ' . self::q($in('message')), null, null];
        if (preg_match('~^aria/~', $p)) return ['chat', 'Asked Aria ' . self::q($in('message') ?? $in('question')), null, null];
        if (preg_match('~^(meetings?|strategy)/.*(start|create)~', $p)) return ['meetings', 'Started a strategy meeting', null, null];
        if (preg_match('~^meetings?/\d+/messages?~', $p)) return ['meetings', 'Spoke in a strategy meeting ' . self::q($in('message') ?? $in('content')), 'Meeting', $id];
        // approvals
        if (preg_match('~^approvals/(\d+)/(approve|approved)~', $p, $x)) return ['approvals', 'Approved ' . (self::title('approvals', $x[1]) ?: 'a request'), 'Approval', (int) $x[1]];
        if (preg_match('~^approvals/(\d+)/(deny|reject|decline)~', $p, $x)) return ['approvals', 'Declined ' . (self::title('approvals', $x[1]) ?: 'a request'), 'Approval', (int) $x[1]];
        if (preg_match('~^(growth/)?campaigns/(\d+)/(approve|accept|resume)~', $p, $x)) return ['campaigns', 'Approved a campaign ' . self::title('campaigns', $x[2], 'name'), 'Campaign', (int) $x[2]];
        if (preg_match('~^(growth/)?campaigns/(\d+)/(decline|pause|stop)~', $p, $x)) return ['campaigns', ucfirst($x[3] === 'decline' ? 'declined' : ($x[3] . 'd')) . ' a campaign ' . self::title('campaigns', $x[2], 'name'), 'Campaign', (int) $x[2]];
        // websites
        if (preg_match('~^builder/pages/(\d+)/arthur-edit$~', $p, $x)) { [$wid, $n] = self::pageSite($x[1]); return ['websites', 'Asked Arthur ' . self::q($in('message')) . ' on ' . $n, 'Website', $wid]; }
        if (preg_match('~^builder/arthur/message$~', $p)) return ['websites', 'Worked with Arthur on a new website ' . self::q($in('message')), null, null];
        if ($m === 'POST' && $p === 'builder/websites') return ['websites', 'Started a new website', null, null];
        if (preg_match('~^builder/websites/(\d+)/(publish)$~', $p, $x)) return ['websites', 'Published ' . self::site($x[1]), 'Website', (int) $x[1]];
        if (preg_match('~^builder/websites/(\d+)/unpublish$~', $p, $x)) return ['websites', 'Took ' . self::site($x[1]) . ' offline', 'Website', (int) $x[1]];
        if (preg_match('~^websites/(\d+)/delete$~', $p, $x)) return ['websites', 'Deleted the website ' . self::site($x[1]), 'Website', (int) $x[1]];
        if (preg_match('~^builder/websites/(\d+)/(palette|fonts|layout|site-icon|contact|about|tracking|set-subdomain|domain|fields/[^/]+|undo|restore|logo|blocks/[^/]+/visibility|catalogue.*)$~', $p, $x)) {
            $what = ['palette' => 'changed the colours', 'fonts' => 'changed the fonts', 'layout' => 'switched the layout', 'site-icon' => ($m === 'DELETE' ? 'went back to the letter icon' : 'uploaded a site icon'), 'contact' => 'updated the contact details', 'about' => 'renamed or described the site', 'tracking' => 'updated the tracking codes', 'set-subdomain' => 'changed the address', 'domain' => 'changed the domain', 'undo' => 'undid a change', 'restore' => 'restored an earlier version', 'logo' => 'changed the logo'];
            $k = explode('/', $x[2])[0];
            $w = $what[$k] ?? (str_starts_with($x[2], 'fields') ? 'edited text or a picture' : (str_starts_with($x[2], 'blocks') ? 'showed or hid a section' : (str_starts_with($x[2], 'catalogue') ? 'edited the catalogue' : 'made a change')));
            return ['websites', ucfirst($w) . ' on ' . self::site($x[1]), 'Website', (int) $x[1]];
        }
        // team and plan
        if ($p === 'team/invite') return ['team', 'Invited ' . (string) $in('email') . ' as ' . ($in('role') === 'admin' ? 'an admin' : 'a member'), null, null];
        if (preg_match('~^team/members/(\d+)/role$~', $p, $x)) return ['team', 'Made ' . self::person($x[1]) . ' ' . ($in('role') === 'admin' ? 'an admin' : 'a member'), 'User', (int) $x[1]];
        if (preg_match('~^team/members/(\d+)$~', $p, $x) && $m === 'DELETE') return ['team', 'Removed ' . self::person($x[1]) . ' from the team', 'User', (int) $x[1]];
        if (preg_match('~^team/invites/(\d+)$~', $p, $x) && $m === 'DELETE') return ['team', 'Cancelled an invitation', null, null];
        if ($p === 'billing/upgrade') return ['billing', 'Changed the plan to ' . self::plan($in('plan_id')), null, null];
        if ($p === 'billing/checkout') return ['billing', 'Started checkout for ' . self::plan($in('plan_id')), null, null];
        if ($p === 'billing/cancel') return ['billing', 'Cancelled the plan', null, null];
        // content, social, CRM, calendar, studio, SEO
        if (preg_match('~^(write/)?articles(/(\d+))?(/([a-z-]+))?$~', $p, $x)) {
            $act = $x[5] ?? ''; $t = isset($x[3]) && $x[3] !== '' ? self::title('articles', $x[3]) : self::q($in('title') ?? $in('topic'), 70);
            $verb = $act === 'publish' ? 'Published the article' : ($act === 'generate-featured-image' ? 'Made a featured image for' : ($m === 'DELETE' ? 'Deleted the article' : ($m === 'POST' && $act === '' ? 'Started an article' : 'Edited the article')));
            return ['content', trim($verb . ' ' . $t), 'Article', isset($x[3]) && $x[3] !== '' ? (int) $x[3] : null];
        }
        if (preg_match('~^social/posts(/(\d+))?(/([a-z-]+))?$~', $p, $x)) {
            $act = $x[4] ?? ''; $verb = $act === 'publish' ? 'Published a social post' : ($act === 'schedule' ? 'Scheduled a social post' : ($act === 'dismiss-preview' ? null : ($m === 'DELETE' ? 'Deleted a social post' : ($m === 'POST' && $act === '' ? 'Created a social post' : 'Edited a social post'))));
            return ['social', $verb, 'SocialPost', isset($x[2]) && $x[2] !== '' ? (int) $x[2] : null];
        }
        if (preg_match('~^social/(comments|messages)/~', $p)) return ['social', 'Replied on social media', null, null];
        if (preg_match('~^(crm/)?(leads|clients|contacts)(/(\d+))?~', $p, $x)) {
            $who = self::q(trim(((string) $in('name')) . ' ' . ((string) $in('first_name')) . ' ' . ((string) $in('last_name'))) ?: (string) $in('email'), 60);
            $verb = $m === 'DELETE' ? 'Deleted a client' : ((($x[4] ?? '') === '' && $m === 'POST') ? 'Added a client' : 'Updated a client');
            return ['clients', trim($verb . ' ' . $who), 'Lead', ($x[4] ?? '') !== '' ? (int) $x[4] : null];
        }
        if (preg_match('~^(calendar/)?events~', $p)) return ['calendar', $m === 'DELETE' ? 'Removed a calendar event' : ($m === 'POST' ? 'Added a calendar event ' . self::q($in('title'), 60) : 'Changed a calendar event'), null, null];
        if (preg_match('~^studio/ai/generate-image$|^creative/generate/image~', $p)) return ['studio', 'Generated an image ' . self::q($in('prompt'), 70), null, null];
        if (preg_match('~^creative/generate/video|^studio/.*video~', $p)) return ['studio', 'Started a video ' . self::q($in('prompt'), 70), null, null];
        if (preg_match('~^media/upload~', $p)) return ['media', 'Uploaded a file to the media library', null, null];
        if (preg_match('~^seo/(.+)$~', $p, $x)) return ['seo', 'Ran ' . str_replace(['/', '-', '_'], ' ', $x[1]) . ' in SEO', null, null];
        if (preg_match('~^(businesses|business)(/(\d+))?~', $p, $x)) return ['business', $m === 'DELETE' ? 'Removed a business' : ($m === 'POST' && ($x[3] ?? '') === '' ? 'Added a business' : 'Updated a business profile'), 'Business', ($x[3] ?? '') !== '' ? (int) $x[3] : null];
        if (preg_match('~^(workspace|settings)(/|$)~', $p)) return ['settings', 'Changed workspace settings', null, null];
        // anything else that changed something: say where
        $area = preg_replace('/[^a-z]/', '', strtolower($seg[0] ?? 'app')) ?: 'app';
        $names = ['builder' => 'Websites', 'social' => 'Social', 'crm' => 'Clients', 'write' => 'Write', 'studio' => 'Studio', 'creative' => 'Studio', 'calendar' => 'Calendar', 'marketing' => 'Marketing', 'growth' => 'Campaigns', 'chatbot' => 'Chatbot', 'domains' => 'Domains', 'email' => 'Email'];
        return [$area, 'Made a change in ' . ($names[$area] ?? ucfirst($area)), null, null];
    }

    /** Owner/admin view: recent activity of the people in this workspace, and per person what they did and spent. */
    public static function feed(int $wsId, ?int $userId, int $days, int $limit, int $beforeId = 0): array
    {
        $since = now()->subDays(max(1, min(365, $days)));
        $members = DB::table('workspace_users')->join('users', 'users.id', '=', 'workspace_users.user_id')->where('workspace_users.workspace_id', $wsId)
            ->get(['users.id', 'users.name', 'users.email', 'workspace_users.role'])->keyBy('id');
        $q = DB::table('audit_logs')->where('workspace_id', $wsId)->whereNotNull('user_id')->where('created_at', '>=', $since)
            ->where(function ($w) { $w->where('action', 'like', 'activity.%')->orWhereIn('action', ['user.login', 'approval.approved', 'approval.denied', 'team.joined']); });
        if ($userId) $q->where('user_id', $userId);
        if ($beforeId > 0) $q->where('id', '<', $beforeId);
        $rows = $q->orderByDesc('id')->limit(max(1, min(200, $limit)))->get(['id', 'user_id', 'action', 'metadata_json', 'created_at']);
        $items = [];
        foreach ($rows as $row) {
            $md = json_decode((string) $row->metadata_json, true) ?: [];
            $text = $md['text'] ?? ($row->action === 'user.login' ? 'Signed in' : ($row->action === 'approval.approved' ? 'Approved a request' : ($row->action === 'approval.denied' ? 'Declined a request' : ($row->action === 'team.joined' ? 'Joined the team' : str_replace('.', ' ', $row->action)))));
            $mem = $members[$row->user_id] ?? null;
            $items[] = ['id' => (int) $row->id, 'at' => (string) $row->created_at, 'user_id' => (int) $row->user_id, 'name' => $mem ? ($mem->name ?: $mem->email) : 'A former member', 'area' => preg_replace('/^activity\./', '', (string) $row->action), 'text' => $text];
        }
        // per person over the window: actions, last seen, credits spent (reservations carry acting_user_id)
        $people = [];
        foreach ($members as $mid => $mem) {
            $acts = DB::table('audit_logs')->where('workspace_id', $wsId)->where('user_id', $mid)->where('created_at', '>=', $since)->where('action', 'like', 'activity.%')->count();
            $last = DB::table('audit_logs')->where('workspace_id', $wsId)->where('user_id', $mid)->max('created_at');
            $spent = (int) DB::table('credit_transactions as c')->join('credit_transactions as r', function ($j) { $j->on('r.reservation_reference', '=', 'c.reservation_reference')->where('r.type', '=', 'reserve'); })
                ->where('c.type', 'commit')->where('c.workspace_id', $wsId)->where('c.created_at', '>=', $since)
                ->whereRaw("JSON_EXTRACT(r.metadata_json, '$.acting_user_id') = ?", [(int) $mid])->sum('c.amount');
            $people[] = ['user_id' => (int) $mid, 'name' => $mem->name ?: $mem->email, 'role' => $mem->role, 'actions' => $acts, 'credits_spent' => $spent, 'last_seen' => $last];
        }
        usort($people, fn ($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));
        return ['items' => $items, 'people' => $people, 'days' => $days, 'more' => count($items) >= $limit, 'next_before' => $items ? end($items)['id'] : null];
    }
}
