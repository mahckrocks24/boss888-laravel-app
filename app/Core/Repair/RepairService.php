<?php

namespace App\Core\Repair;

use App\Core\Billing\CreditService;
use App\Core\OwnerModel\OwnerModelService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P3: when the owner is frustrated, Sarah names the failure, fixes it, pays for it and goes quiet for two days.
 * Policy = DEC-0073 D1 (defaults, Owner may override):
 *   - a charged deliverable that failed or was rejected is refunded on the spot, once, idempotent (credit_transactions
 *     metadata repair_refund); failed tasks already release their reservation, so this mostly hits QA- or owner-rejected work
 *   - a repeat episode inside 30 days earns a goodwill credit (GOODWILL credits) under a flat monthly cap (GOODWILL_CAP) -
 *     no per-plan allowance exists in config yet, so the cap is flat and recorded as a known limit
 *   - a third episode inside 30 days (or a second severe one) is escalated to the house workspace as a notification
 *   - every strong/severe episode starts a 48-hour cool-down: no brand questions, no check-ins, no "I kept that" echoes
 *   - the debt is a relationship fact (debt_<episode>) in the Memory Pack until the owner accepts the fix or approves the
 *     next deliverable; closing writes memory_events repair_closed
 * Nothing here is a model call. The directive for the turn is prepended to the Memory Pack; the compensation line is
 * appended to the reply as exact facts (credits and balance), never left to the model to paraphrase.
 */
class RepairService
{
    public const GOODWILL = 5;          // credits per repeat episode
    public const GOODWILL_CAP = 30;     // credits per workspace per calendar month (flat; see RFC-0023 known limits)
    public const COOLDOWN_HOURS = 48;
    public const EPISODE_WINDOW_MIN = 45;   // frustrated turns this close together are one episode

    public function __construct(private FrustrationDetector $detector, private OwnerModelService $model, private CreditService $credits) {}

    /** @return array{directive:string, append:?string, episode_id:int, level:string}|null */
    public function onTurn(int $wsId, ?int $bizId, ?int $userId, ?int $userMessageId, string $content): ?array
    {
        $read = $this->detector->read($wsId, $bizId, $content, $userMessageId);
        $open = DB::table('repair_log')->where('workspace_id', $wsId)->where('status', 'open')->orderByDesc('id')->first();

        if ($read['level'] === 'none') {
            // the owner sounds pleased while a repair is open: the loop closes, briefly and without a second apology
            if ($open && in_array('voice:pleased', $read['cues'], true)) {
                $this->close((int) $open->id, 'owner_pleased');
                return ['directive' => "REPAIR CLOSED (this turn): the owner has just accepted your fix for \"" . mb_substr((string) $open->cause, 0, 100) . "\". One short line of thanks, no second apology, no recap of what went wrong; then carry on with what they asked.\n\n", 'append' => null, 'episode_id' => (int) $open->id, 'level' => 'closed'];
            }
            return null;
        }

        $cause = $read['cause'];
        $now = now();
        $append = null; $comp = 'none'; $credits = 0;

        // one episode per 45 minutes: a second frustrated turn deepens it instead of opening another
        if ($open && strtotime((string) $open->updated_at) >= time() - self::EPISODE_WINDOW_MIN * 60) {
            $hits = (int) $open->hits + 1;
            $level = $this->maxLevel((string) $open->level, $read['level']);
            DB::table('repair_log')->where('id', $open->id)->update(['hits' => $hits, 'level' => $level, 'score' => max((int) $open->score, $read['score']), 'updated_at' => $now,
                'cues_json' => json_encode(array_values(array_unique(array_merge(json_decode((string) $open->cues_json, true) ?: [], $read['cues']))))]);
            $id = (int) $open->id;
            if ($hits >= 3 && $open->compensation !== 'escalated') { $this->escalate($id, $wsId, $bizId, 'three frustrated turns in one episode'); $comp = 'escalated'; }
            $this->model->event($wsId, 'repair_hit', ['episode' => $id, 'hits' => $hits, 'level' => $level, 'cues' => $read['cues']]);
            return ['directive' => $this->directive($level, $read['cues'], $cause, $comp, $hits), 'append' => null, 'episode_id' => $id, 'level' => $level];
        }

        $id = (int) DB::table('repair_log')->insertGetId([
            'workspace_id' => $wsId, 'business_id' => $bizId, 'user_message_id' => $userMessageId ?: null, 'score' => $read['score'], 'level' => $read['level'],
            'cues_json' => json_encode($read['cues']), 'cause_ref' => $cause['ref'] ?? null, 'cause' => $cause ? ($cause['what'] . ' - ' . $cause['why']) : null,
            'status' => 'open', 'hits' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->model->observe($wsId, $bizId, 'frustration', 'repair:' . $id, ['level' => $read['level'], 'score' => $read['score'], 'cues' => $read['cues'], 'cause' => $cause['ref'] ?? null]);

        // 1. refund the charged deliverable the owner is talking about (D1)
        $refund = ($cause && in_array($read['level'], ['strong', 'severe'], true)) ? $this->refund($wsId, $cause, $id) : null;   // mild = name it and fix it; money only when it is clearly a grievance
        if ($refund) { $comp = 'refund'; $credits = $refund['credits']; $append = $refund['line']; }

        // 2. repeat inside 30 days and nothing to refund: goodwill under the monthly cap
        $priorEpisodes = DB::table('repair_log')->where('workspace_id', $wsId)->where('id', '<>', $id)->where('created_at', '>=', $now->copy()->subDays(30))->count();
        if (! $refund && $priorEpisodes >= 1 && in_array($read['level'], ['strong', 'severe'], true)) {
            $gw = $this->goodwill($wsId, $id, $cause);
            if ($gw) { $comp = 'goodwill'; $credits = $gw['credits']; $append = $gw['line']; }
        }

        // 3. third episode in 30 days, or a second severe one: the house hears about it
        $severePrior = DB::table('repair_log')->where('workspace_id', $wsId)->where('id', '<>', $id)->where('level', 'severe')->where('created_at', '>=', $now->copy()->subDays(30))->count();
        if ($priorEpisodes >= 2 || ($read['level'] === 'severe' && $severePrior >= 1)) { $this->escalate($id, $wsId, $bizId, $priorEpisodes >= 2 ? 'third episode in 30 days' : 'second severe episode in 30 days'); $comp = $comp === 'none' ? 'escalated' : $comp; }

        // 4. cool-down and the debt the owner is owed
        if (in_array($read['level'], ['strong', 'severe'], true)) $this->startCooldown($wsId);
        $debt = 'You owe the owner a fix' . ($cause ? ' for: ' . $cause['what'] . ' (' . $cause['why'] . ', ' . $cause['when'] . ')' : ' for the last thing that went wrong') . ($credits ? '; ' . $credits . ' credits already returned' : '') . '. Make it right before asking for anything new.';
        try { $this->model->upsert($wsId, $bizId, 'relationship', 'debt_' . $id, $debt, 'computed', 1.0, 'repair:' . $id, 'confirmed', 30); } catch (\Throwable) {}

        DB::table('repair_log')->where('id', $id)->update(['compensation' => $comp, 'credits' => $credits, 'updated_at' => now()]);
        $this->model->event($wsId, 'repair_episode', ['episode' => $id, 'level' => $read['level'], 'score' => $read['score'], 'cues' => $read['cues'], 'cause' => $cause['ref'] ?? null, 'compensation' => $comp, 'credits' => $credits]);
        Log::info('[REPAIR] episode', ['ws' => $wsId, 'id' => $id, 'level' => $read['level'], 'score' => $read['score'], 'comp' => $comp, 'credits' => $credits]);

        return ['directive' => $this->directive($read['level'], $read['cues'], $cause, $comp, 1, $credits), 'append' => $append, 'episode_id' => $id, 'level' => $read['level']];
    }

    /** The owner approved a deliverable while a repair is open: the fix landed. */
    public function closeOnApproval(int $wsId, string $via = 'approval'): void
    {
        try {
            $open = DB::table('repair_log')->where('workspace_id', $wsId)->where('status', 'open')->orderByDesc('id')->first();
            if ($open) $this->close((int) $open->id, $via);
        } catch (\Throwable) {}
    }

    /** Quiet period after a strong episode: brand questions, check-ins and memory echoes wait. */
    public static function cooldownActive(int $wsId): bool
    {
        try {
            $s = json_decode((string) DB::table('workspaces')->where('id', $wsId)->value('settings_json'), true) ?: [];
            $until = $s['sarah_cooldown_until'] ?? null;
            return $until && strtotime((string) $until) > time();
        } catch (\Throwable) { return false; }
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function directive(string $level, array $cues, ?array $cause, string $comp, int $hits, int $credits = 0): string
    {
        $voice = array_values(array_filter($cues, fn ($c) => str_starts_with($c, 'voice:')));
        $cueTxt = $voice ? str_replace(['voice:', '_'], ['', ' '], implode(', ', array_slice($voice, 0, 3))) : 'repeated asks after a failure of yours';
        $causeTxt = $cause ? "On record: {$cause['what']} - {$cause['why']} ({$cause['when']})." : 'Nothing specific is on record for it, so ask one precise question about what is wrong - not "can you tell me more".';
        $compTxt = match ($comp) {
            'refund'   => "The refund line is already appended to your reply as exact figures: do not restate it, do not change the numbers.",
            'goodwill' => "A goodwill credit line is already appended to your reply: do not restate it.",
            'escalated' => "This has been escalated to the team; say so in one plain sentence ('I've flagged this to the team') without promising a timeline.",
            default    => "No credits were charged for the failure, so do not offer compensation; the fix itself is the repair.",
        };
        $again = $hits > 1 ? " This is frustrated turn {$hits} of the same episode: your last attempt did not land. Change the approach, do not repeat it." : '';
        return "REPAIR (this turn, the owner sounds {$level}ly frustrated: {$cueTxt}).{$again} {$causeTxt}\n"
            . "Do this, in this order, in your own words: (1) one sentence naming the specific failure plainly - no 'I understand your frustration', no 'I apologise for any inconvenience', no boilerplate; "
            . "(2) say exactly what you are doing differently right now, and if it is a deliverable, deliver it in this reply or create the task now; "
            . "(3) {$compTxt} (4) ask nothing new of the owner: no brand questions, no check-ins, no upsell, no 'would you like me to'. "
            . "Never claim a fix you have not made. Keep it short.\n\n";
    }

    private function refund(int $wsId, array $cause, int $episodeId): ?array
    {
        try {
            $taskId = $cause['task_id'] ?? null;
            if (! $taskId) return null;
            $committed = (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_type', 'Task')->where('reference_id', $taskId)->where('type', 'commit')->sum('amount');
            if ($committed <= 0) return null;
            $already = DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_type', 'Task')->where('reference_id', $taskId)->where('type', 'credit')
                ->whereRaw("JSON_EXTRACT(metadata_json, '$.repair_refund') = true")->exists();
            if ($already) return null;
            $this->credits->credit($wsId, $committed, 'Task', (int) $taskId, ['repair_refund' => true, 'repair_log_id' => $episodeId, 'reason' => mb_substr($cause['why'], 0, 160)]);
            $bal = $this->credits->getBalance($wsId)['balance'] ?? null;
            $this->model->event($wsId, 'repair_refund', ['episode' => $episodeId, 'task' => $taskId, 'credits' => $committed]);
            $what = mb_strtolower(mb_substr(preg_replace('/\s+/', ' ', (string) $cause['what']), 0, 70));
            $line = "I've returned the {$committed} credit" . ($committed === 1 ? '' : 's') . " charged for " . rtrim($what, '.,;:') . ' on ' . date('j M', strtotime($cause['when'])) . '.' . ($bal !== null ? ' Your balance is now ' . (int) round((float) $bal) . '.' : '');
            return ['credits' => $committed, 'line' => $line];
        } catch (\Throwable $e) { Log::warning('[REPAIR] refund failed: ' . $e->getMessage(), ['ws' => $wsId]); return null; }
    }

    private function goodwill(int $wsId, int $episodeId, ?array $cause): ?array
    {
        try {
            $used = (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_type', 'repair_goodwill')->where('type', 'credit')->where('created_at', '>=', now()->startOfMonth())->sum('amount');
            // DEC-0073 D1: cap = 10% of the plan's monthly credits when the workspace carries one, else the flat cap
            $allow = (int) DB::table('workspaces')->where('id', $wsId)->value('monthly_credit_allowance');
            $cap = $allow > 0 ? max(self::GOODWILL, (int) floor($allow * 0.10)) : self::GOODWILL_CAP;
            $n = min(self::GOODWILL, $cap - $used);
            if ($n <= 0) return null;
            $this->credits->credit($wsId, $n, 'repair_goodwill', $episodeId, ['repair_log_id' => $episodeId, 'cause' => $cause['ref'] ?? null]);
            $bal = $this->credits->getBalance($wsId)['balance'] ?? null;
            $this->model->event($wsId, 'repair_goodwill', ['episode' => $episodeId, 'credits' => $n, 'month_used' => $used + $n]);
            return ['credits' => $n, 'line' => "I've added {$n} credits to your account for the trouble this has cost you." . ($bal !== null ? ' Your balance is now ' . (int) round((float) $bal) . '.' : '')];
        } catch (\Throwable $e) { Log::warning('[REPAIR] goodwill failed: ' . $e->getMessage(), ['ws' => $wsId]); return null; }
    }

    private function escalate(int $episodeId, int $wsId, ?int $bizId, string $why): void
    {
        try {
            $ep = DB::table('repair_log')->where('id', $episodeId)->first();
            $wsName = (string) (DB::table('workspaces')->where('id', $wsId)->value('business_name') ?: DB::table('workspaces')->where('id', $wsId)->value('name') ?: ('workspace ' . $wsId));
            app(\App\Core\Notifications\NotificationService::class)->send(1, 'in_app', 'repair.escalated', [
                'workspace_id' => $wsId, 'business_id' => $bizId, 'workspace' => $wsName, 'episode' => $episodeId, 'why' => $why,
                'level' => $ep->level ?? null, 'cause' => $ep->cause ?? null, 'hits' => $ep->hits ?? 1, 'compensation' => $ep->compensation ?? 'none',
            ]);
            DB::table('repair_log')->where('id', $episodeId)->update(['status' => 'escalated', 'compensation' => DB::raw("IF(compensation='none','escalated',compensation)"), 'updated_at' => now()]);
            $this->model->event($wsId, 'repair_escalated', ['episode' => $episodeId, 'why' => $why]);
            Log::warning('[REPAIR] escalated', ['ws' => $wsId, 'episode' => $episodeId, 'why' => $why]);
        } catch (\Throwable $e) { Log::warning('[REPAIR] escalate failed: ' . $e->getMessage(), ['ws' => $wsId]); }
    }

    private function startCooldown(int $wsId): void
    {
        try {
            $s = json_decode((string) DB::table('workspaces')->where('id', $wsId)->value('settings_json'), true) ?: [];
            $s['sarah_cooldown_until'] = now()->addHours(self::COOLDOWN_HOURS)->toIso8601String();
            DB::table('workspaces')->where('id', $wsId)->update(['settings_json' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable) {}
    }

    private function close(int $episodeId, string $via): void
    {
        $ep = DB::table('repair_log')->where('id', $episodeId)->first();
        if (! $ep || $ep->status === 'closed') return;
        DB::table('repair_log')->where('id', $episodeId)->update(['status' => 'closed', 'closed_at' => now(), 'fix_json' => json_encode(['closed_via' => $via]), 'updated_at' => now()]);
        $ids = DB::table('owner_model_facts')->where('workspace_id', $ep->workspace_id)->where('group', 'relationship')->where('key', 'debt_' . $episodeId)->pluck('id')->all();
        if ($ids) $this->model->dismiss((int) $ep->workspace_id, $ids);
        $mins = (int) round((time() - strtotime((string) $ep->created_at)) / 60);
        $this->model->event((int) $ep->workspace_id, 'repair_closed', ['episode' => $episodeId, 'via' => $via, 'minutes_open' => $mins, 'hits' => $ep->hits, 'compensation' => $ep->compensation, 'credits' => $ep->credits]);
        Log::info('[REPAIR] closed', ['ws' => $ep->workspace_id, 'episode' => $episodeId, 'via' => $via, 'minutes' => $mins]);
    }

    private function maxLevel(string $a, string $b): string
    {
        $rank = ['none' => 0, 'mild' => 1, 'strong' => 2, 'severe' => 3];
        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }
}
