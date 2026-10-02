<?php

namespace App\Core\OwnerModel;

use Illuminate\Support\Facades\DB;

/**
 * RFC-0023 P1. The ONE reader for Sarah's turn: a bounded, dated, sourced block of what she knows about this owner.
 * Order (cut from the bottom, cut disclosed): relationship and debts; vision and goals; preferences and corrections (all,
 * newest first); last outcomes; journal with dates; open wants; behaviour (computed). Every pack build is logged.
 */
final class MemoryPack
{
    public const BUDGET = 6000;

    public function __construct(private OwnerModelService $model) {}

    public function build(int $wsId, ?int $bizId, string $message = '', int $budget = self::BUDGET): string
    {
        $sections = [];
        try {
            $facts = $this->model->facts($wsId, $bizId);
            $by = []; foreach ($facts as $f) $by[$f->group][] = $f;
            $line = function ($f) { $d = substr((string) ($f->last_confirmed_at ?: $f->first_seen_at ?: $f->created_at), 0, 10); $tag = $f->status === 'proposed' ? ' [unconfirmed guess]' : ''; $src = $f->source === 'stated' ? 'owner said' : ($f->source === 'observed' ? 'observed' : $f->source); return "  - {$f->value} ({$src}, {$d}){$tag}"; };

            $rel = array_map($line, $by['relationship'] ?? []);
            $debts = DB::table('owner_model_facts')->where('workspace_id', $wsId)->where('group', 'relationship')->where('key', 'like', 'debt_%')->where('status', 'confirmed')->count();
            if ($rel || $debts) $sections[] = "RELATIONSHIP (how things stand between you and the owner" . ($debts ? "; {$debts} open debt(s) you owe before asking for anything new" : '') . "):\n" . implode("\n", $rel ?: ['  - nothing recorded yet']);

            $vg = array_merge(array_map($line, $by['identity'] ?? []), array_map($line, $by['goals'] ?? []));
            if ($vg) $sections[] = "THE OWNER'S VISION AND GOALS (in their words; measure progress against the campaigns, never assert it):\n" . implode("\n", $vg);

            // REPORT-0071 rerun: only CONFIRMED preferences are rules; unconfirmed guesses are listed apart and never obeyed
            $pref = array_map($line, array_values(array_filter($by['preferences'] ?? [], fn ($f) => $f->status === 'confirmed')));
            $guesses = array_map($line, array_values(array_filter($by['preferences'] ?? [], fn ($f) => $f->status !== 'confirmed')));
            try {
                foreach (DB::table('experience_owner_feedback')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(180))->orderByDesc('id')->limit(8)->get(['feedback_type', 'value', 'created_at']) as $fb) {
                    $pref[] = '  - ' . trim(mb_substr((string) $fb->value, 0, 160)) . ' (' . strtolower(str_replace('_', ' ', (string) $fb->feedback_type)) . ', ' . substr((string) $fb->created_at, 0, 10) . ')';
                }
            } catch (\Throwable) {}
            if ($pref) $sections[] = "STANDING PREFERENCES AND CORRECTIONS (obey every one; the newest wins when two conflict):\n" . implode("\n", $pref);
            if ($guesses) $sections[] = "THINGS YOU THINK YOU NOTICED (unconfirmed - do NOT act on them as rules; reply in the language of the owner's current message):\n" . implode("\n", array_slice($guesses, 0, 5));

            $out = app(\App\Core\OutcomeLedger\OutcomeLedgerService::class)->recent($wsId, $bizId, 3, $message) ?: $this->outcomes($wsId, $bizId);   // RFC-0023 P2: the ledger first
            if ($out) $sections[] = "LAST OUTCOMES (what the owner's campaigns and work produced; cite them, do not embellish):\n" . implode("\n", $out);

            $jr = [];
            try { foreach (DB::table('business_journal')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(90))->orderByDesc('id')->limit(8)->get(['kind', 'text', 'created_at']) as $j) $jr[] = '  - ' . substr((string) $j->created_at, 0, 10) . ' ' . $j->kind . ': ' . mb_substr((string) $j->text, 0, 220); } catch (\Throwable) {}
            if ($jr) $sections[] = "WHAT THE OWNER TOLD YOU ABOUT THE BUSINESS (dated; follow up naturally):\n" . implode("\n", $jr);
            // RFC-0023 P4: recall beyond the visible window, retrieved for this message (kill switch storage/app/recall1.on)
            try { $rc = $message !== '' ? app(\App\Core\Recall\MemoryRecall::class)->recall($wsId, $bizId, $message) : []; } catch (\Throwable) { $rc = []; }
            if ($rc) $sections[] = "RELEVANT EARLIER MOMENTS (retrieved from the whole history for this message; quote them with their date, never invent one, and say so when nothing earlier covers the question):\n" . implode("\n", $rc);

            $wants = array_map($line, $by['wants'] ?? []);
            $inter = array_map($line, $by['interests'] ?? []);
            if ($wants) $sections[] = "OPEN WANTS (things the owner asked for that are not yet delivered; close them before proposing new ones):\n" . implode("\n", $wants);
            if ($inter) $sections[] = "WHAT THE OWNER CARES ABOUT:\n" . implode("\n", $inter);

            $beh = $this->model->behaviour($wsId);
            if ($beh) $sections[] = "HOW THE OWNER BEHAVES (computed from what they do; adapt your timing and length to it):\n" . implode("\n", array_map(fn ($k, $v) => "  - {$k}: {$v}", array_keys($beh), $beh));
        } catch (\Throwable $e) {
            $this->model->event($wsId, 'pack_failed', ['e' => mb_substr($e->getMessage(), 0, 200)]);
            return '';
        }
        if (! $sections) { $this->model->event($wsId, 'pack_built', [], 0, false); return ''; }
        $head = "WHAT YOU KNOW ABOUT THIS OWNER (your memory; each line carries its source and date - say \"you told me on <date>\" when you use one):\n";
        $body = ''; $truncated = false;
        foreach ($sections as $s) {
            $cand = $s . "\n\n";
            if (mb_strlen($head) + mb_strlen($body) + mb_strlen($cand) > $budget) { $truncated = true; break; }
            $body .= $cand;
        }
        if ($truncated) $body .= "[Memory cut at " . number_format($budget) . " characters for this turn; older items are retrievable on request.]\n";
        $pack = $head . $body;
        $this->model->event($wsId, 'pack_built', ['sections' => count($sections)], mb_strlen($pack), $truncated);
        return $pack . "\n";
    }

    /** P1 stopgap until the Outcome Ledger (P2): the last closed campaigns with a recorded result. */
    private function outcomes(int $wsId, ?int $bizId): array
    {
        $lines = [];
        try {
            $q = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereIn('status', ['completed', 'done', 'closed', 'ended'])->orderByDesc('updated_at')->limit(3);
            if ($bizId) $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id'));
            foreach ($q->get() as $c) {
                $res = json_decode((string) ($c->results_json ?? ''), true) ?: [];
                $bits = []; foreach (['posts' => 'posts', 'articles' => 'articles', 'leads' => 'leads', 'clicks' => 'clicks', 'reach' => 'reach', 'summary' => null] as $k => $label) { if (isset($res[$k]) && $res[$k] !== '' && $res[$k] !== null) $bits[] = $label ? "{$res[$k]} {$label}" : mb_substr((string) $res[$k], 0, 120); }
                $lines[] = '  - ' . substr((string) $c->updated_at, 0, 10) . ' "' . mb_substr((string) ($c->title ?? $c->name ?? 'campaign'), 0, 60) . '": ' . ($bits ? implode(', ', $bits) : 'closed, no measured result') . (! empty($c->decline_reason) ? ' (declined: ' . mb_substr((string) $c->decline_reason, 0, 80) . ')' : '');
            }
        } catch (\Throwable) {}
        return $lines;
    }
}
