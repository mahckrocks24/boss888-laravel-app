<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;

/**
 * SARAH-GATE-1: what Sarah says when the workspace's plan does not include her (an expired trial on Free, or Starter).
 * Fixed words on purpose: this is the one moment she must not spend AI the workspace isn't paying for. The prices come
 * from the plans table, so a price change never leaves her quoting an old one.
 */
final class SarahPaused
{
    public static function text(int $wsId): string
    {
        $lite = null;
        try { $lite = DB::table('plans')->where('is_public', 1)->where('includes_dmm', 1)->where('price', '>', 0)->orderBy('price')->first(['name', 'price', 'credit_limit']); } catch (\Throwable $e) {}
        $offer = $lite ? ' ' . $lite->name . ' is $' . (int) $lite->price . ' a month with ' . number_format((int) $lite->credit_limit) . ' credits.' : '';
        $expired = false;
        try { $expired = app(TrialService::class)->isTrialExpired($wsId); } catch (\Throwable $e) {}
        return ($expired
                ? "Your free trial has ended, so I'm paused for now."
                : "Your current plan doesn't include me and the team, so I'm paused for now.")
            . " Your website, contacts and calendar keep working as usual."
            . " Choose a plan under Settings › Plan & billing and I'll pick straight up from here." . $offer;
    }
}