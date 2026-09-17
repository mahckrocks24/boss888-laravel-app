<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RISK-0189 (2026-09-17) — a stated credit balance is the ledger's, never the conversation's memory.
 *
 * "How many credits do I have left?" was answered "You have 2 credits left" four minutes after the ledger had moved
 * to 1 (EV-1059): the lean analytical prompt had omitted the balance line, and the model repeated the figure it had
 * said earlier in the thread. The balance is read here, at reply time, and any figure the reply attaches to the
 * balance is replaced with it — the same discipline SessionLedgerFacts applies to sessions (RISK-0143).
 */
final class CreditBalanceFacts
{
    private const PATTERNS = [
        // "You have 2 credits left", "2 credits remaining", "2 credits left in your account"
        '/\b(you have|you\'ve got|leaving you with|leaving|that leaves|leaves you|balance (?:is|of|reads|now reads|sits at|stands at)|sitting at|down to)\s+(?:about\s+|roughly\s+|around\s+)?(\d+)(\s+credits?\b)/i',
        '/\b(\d+)(\s+credits?\s+(?:left|remaining|available|in (?:your|the) (?:account|balance|wallet)))\b/i',
    ];

    public static function guard(string $reply, int $wsId): string
    {
        if ($wsId <= 0 || trim($reply) === '' || ! preg_match('/\bcredits?\b/i', $reply)) return $reply;
        $bal = DB::table('credits')->where('workspace_id', $wsId)->value('balance');
        if ($bal === null) return $reply;
        $bal = (int) floor((float) $bal);
        $fixed = [];
        $out = preg_replace_callback(self::PATTERNS[0], function ($m) use ($bal, &$fixed) {
            if ((int) $m[2] === $bal) return $m[0];
            $fixed[] = (int) $m[2];
            return $m[1] . ' ' . $bal . $m[3];
        }, $reply) ?? $reply;
        $out = preg_replace_callback(self::PATTERNS[1], function ($m) use ($bal, &$fixed) {
            if ((int) $m[1] === $bal) return $m[0];
            $fixed[] = (int) $m[1];
            return $bal . $m[2];
        }, $out) ?? $out;
        $out = preg_replace('/1 credits/', '1 credit', (string) $out);
        if ($fixed !== []) {
            Log::warning('[Sarah888] RISK-0189 credit balance corrected to the ledger', ['ws' => $wsId, 'said' => $fixed, 'ledger' => $bal]);
        }
        return $out;
    }
}
