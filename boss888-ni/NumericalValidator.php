<?php
namespace App\Core\Integrity;

use Illuminate\Support\Facades\DB;

/**
 * 2026-07-17 — Phase-2C V2. Deterministic post-generation numerical validator.
 * Strips business/performance numbers from an executive-facing reply that are
 * not backed by (or contradict) the workspace's retrieved evidence. Mirrors the
 * locked audit scorer's rules. Model-agnostic; runs AFTER synthesis, BEFORE send.
 */
class NumericalValidator
{
    /** Build the set of numeric values this workspace can legitimately state. */
    public function evidenceSet(int $wsId): array
    {
        $ints = [];
        $add = function ($v) use (&$ints) {
            if ($v !== null && $v !== '') { $ints[(int) round((float) $v)] = true; }
        };
        $since = now()->subDays(28)->toDateString();
        $ti = 0; $tc = 0;
        foreach (DB::table('gsc_metrics')->where('workspace_id', $wsId)->where('date', '>=', $since)
                    ->selectRaw('SUM(impressions) i, SUM(clicks) c')->groupBy('query')->get() as $r) {
            $add($r->i); $add($r->c); $ti += (int) $r->i; $tc += (int) $r->c;
        }
        $add($ti); $add($tc);
        $totImpr = $ti; $totClicks = $tc;
        $ctrs = [0.0];
        if ($ti > 0) $ctrs[] = round($tc / $ti * 100, 2);
        foreach (DB::table('seo_keywords')->where('workspace_id', $wsId)->get(['volume', 'current_rank', 'previous_rank']) as $k) {
            $add($k->volume); $add($k->current_rank); $add($k->previous_rank);
        }
        try {
            $add(DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->count());
            $add(DB::table('articles')->where('workspace_id', $wsId)->where('status', 'draft')->count());
            $add(DB::table('articles')->where('workspace_id', $wsId)->count());
            $add(DB::table('seo_content_index')->where('workspace_id', $wsId)->count());
            $add(DB::table('seo_content_index')->where('workspace_id', $wsId)->avg('content_score'));
            foreach (['new', 'contacted', 'qualified', 'converted', 'lost'] as $st) {
                $add(DB::table('leads')->where('workspace_id', $wsId)->where('status', $st)->count());
            }
            $add(DB::table('leads')->where('workspace_id', $wsId)->count());
            $add(DB::table('contacts')->where('workspace_id', $wsId)->count());
            $add(DB::table('deals')->where('workspace_id', $wsId)->count());
            $add(DB::table('seo_keywords')->where('workspace_id', $wsId)->count());
        } catch (\Throwable $e) {}
        return ['ints' => $ints, 'ctrs' => $ctrs, 'tot_impr' => $totImpr, 'tot_clicks' => $totClicks];
    }

    /** @return array{reply:string, stripped:array} */
    public function validate(string $reply, int $wsId, string $userMsg = ''): array
    {
        $ev = $this->evidenceSet($wsId);
        $ctrs = $ev['ctrs'];
        $totImpr = (int) $ev['tot_impr']; $totClicks = (int) $ev['tot_clicks'];
        // impressions ceiling with margin to survive rolling-window drift; a value
        // above this cannot be a real impression figure (e.g. 786 vs total ~162).
        $imprCeil = max((int) round($totImpr * 1.5), $totImpr + 30);
        $qNums = [];
        if (preg_match_all('/\d[\d,]*/', $userMsg, $qm)) {
            foreach ($qm[0] as $x) $qNums[(int) str_replace(',', '', $x)] = true;
        }
        $METRIC = '/traffic|visitor|session|click|ctr|click-through|impression|conversion|convert|\broi\b|return|revenue|dollar|cac|acquisition cost|ltv|lifetime value|growth|grow|increase|lift|forecast|project|expect|payback|break-even|share of voice|tam|market size|deal|profit|margin/i';
        $TIME   = '/^\s*(day|days|week|weeks|month|months|year|years|hour|hours|hr|hrs|min|mins|minute|minutes|q[1-4]|quarter|d(?![a-z])|mo(?![a-z]))/i';

        $spans = []; // [start, len, token, reason]

        // ---- Percentage pass ----
        if (preg_match_all('/\d+(?:\.\d+)?\s*(?:-\s*\d+(?:\.\d+)?)?\s*(?:%|percent)/i', $reply, $pm, PREG_OFFSET_CAPTURE)) {
            foreach ($pm[0] as $mm) {
                $tok = $mm[0]; $off = $mm[1];
                preg_match_all('/\d+(?:\.\d+)?/', $tok, $nn);
                $ok = true;
                foreach ($nn[0] as $vs) {
                    $v = (float) $vs;
                    if ($v == 0.0 || $v == 100.0) continue;
                    if (in_array(round($v, 2), $ctrs, true)) continue;
                    if (isset($qNums[(int) $v])) continue;
                    $ok = false; break;
                }
                if (!$ok) $spans[] = [$off, strlen($tok), $tok, 'unsupported_pct'];
            }
        }

        // ---- Number pass ($amounts + plain metric integers) ----
        if (preg_match_all('/\$?\d[\d,]*(?:\.\d+)?/', $reply, $nm, PREG_OFFSET_CAPTURE)) {
            foreach ($nm[0] as $mm) {
                $tok = $mm[0]; $off = $mm[1];
                // skip if inside a % span already taken
                $inPct = false;
                foreach ($spans as $sp) { if ($off >= $sp[0] && $off < $sp[0] + $sp[1]) { $inPct = true; break; } }
                if ($inPct) continue;
                $ctx = substr($reply, max(0, $off - 45), min(strlen($reply), 90 + strlen($tok)));
                $after = substr($reply, $off + strlen($tok), 10);
                if (preg_match('/^20\d\d$/', $tok)) continue;              // year
                if (preg_match($TIME, $after)) continue;                    // time window
                if (preg_match('/credit|\bcr\b/i', $ctx)) continue;         // pricing
                $dollar = str_contains($tok, '$');
                $val = (int) preg_replace('/[^\d]/', '', $tok);
                if (isset($qNums[$val])) continue;                          // echo of question
                if ($dollar) {
                    if (preg_match($METRIC, $ctx)) $spans[] = [$off, strlen($tok), $tok, 'unsupported_money'];
                    continue;
                }
                // Contradiction-based (high precision): the metric UNIT must
                // immediately follow the number (its actual unit), not merely be
                // nearby — otherwise "155 articles, ... 0 clicks" would falsely
                // treat 155 as a clicks figure.
                $unit = substr($reply, $off + strlen($tok), 16);
                // (a) any CLICKS figure above the true total (ws2 = 0) is fabricated.
                if (preg_match('/^\s*(total\s+)?clicks?\b/i', $unit) && $val > $totClicks && $val > 0) {
                    $spans[] = [$off, strlen($tok), $tok, 'clicks_exceed_actual']; continue;
                }
                // (b) any IMPRESSIONS figure above the total (+margin) is impossible.
                if (preg_match('/^\s*(total\s+)?impressions?\b/i', $unit) && $val > $imprCeil) {
                    $spans[] = [$off, strlen($tok), $tok, 'impressions_exceed_total']; continue;
                }
            }
        }

        if (empty($spans)) return ['reply' => $reply, 'stripped' => []];
        usort($spans, fn ($a, $b) => $b[0] <=> $a[0]); // right-to-left
        $stripped = [];
        foreach ($spans as $sp) {
            [$off, $len, $tok, $reason] = $sp;
            $reply = substr($reply, 0, $off) . '(unverified)' . substr($reply, $off + $len);
            $stripped[] = ['token' => $tok, 'reason' => $reason];
        }
        return ['reply' => $reply, 'stripped' => array_reverse($stripped)];
    }
}
