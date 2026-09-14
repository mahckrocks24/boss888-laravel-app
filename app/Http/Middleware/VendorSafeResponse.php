<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * OWNER RULE (2026-09-14): "the system must never ever reveal our vendors, and must give a generic system
 * maintenance error". A customer-facing JSON response may not carry a provider's name, an upstream HTTP code or a
 * raw provider payload in any error-like field. This guard sits at the API boundary so every engine, route and
 * assistant is covered at once; the raw text stays in our own log, where it belongs.
 *
 * It only rewrites strings that (a) sit under an error-like key and (b) read as an error AND name a vendor or
 * carry a raw upstream code — so a customer's own article that mentions a vendor, or an assistant explaining what
 * a vendor is, passes through untouched. Admin console responses (api/admin/*) are exempt: operators need the detail.
 */
class VendorSafeResponse
{
    public const GENERIC = 'This part of the service is undergoing maintenance. Please try again shortly — nothing was charged.';

    /** Keys whose string values are customer-facing explanations of a failure. */
    private const KEYS = ['error', 'errors', 'message', 'reply', 'detail', 'details', 'reason', 'error_message', 'provider_error', 'failure', 'note', 'hint', 'status_message', 'msg'];

    private const VENDORS = '/\b(open\s?ai|dall[\s\-·]?e|gpt[\s\-]?(image|4|4o|5|3\.5)|chat\s?gpt|minimax|hailuo|deepseek|runway(ml)?|anthropic|claude|gemini|mistral|groq|stability\s?ai|replicate|fal\.ai|eleven\s?labs|railway|cloudflare|digital\s?ocean|sendgrid|mailgun|postmark|migadu|namecheap|resend|twilio|vonage)\b/i';

    private const ERRORISH = '/\b(error|failed|failure|declined|unavailable|exception|quota|rate.?limit|credits? remaining|not configured|api key|invalid api|timed? ?out|unauthori[sz]ed|forbidden)\b|\b(4\d\d|5\d\d)\b|\{\s*"error"/i';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            if (! $request->is('api/*') || $request->is('api/admin/*')) { return $response; }
            $ct = (string) $response->headers->get('Content-Type', '');
            if (stripos($ct, 'json') === false) { return $response; }
            $raw = (string) $response->getContent();
            if ($raw === '' || ! preg_match(self::VENDORS, $raw)) { return $response; }   // fast path: no vendor named anywhere
            $data = json_decode($raw, true);
            if (! is_array($data)) { return $response; }
            $hits = [];
            $clean = $this->walk($data, null, $hits);
            if ($hits !== []) {
                Log::warning('[VendorSafeResponse] provider detail removed from a customer response', [
                    'path' => $request->path(), 'workspace' => $request->attributes->get('workspace_id'), 'removed' => array_slice($hits, 0, 5),
                ]);
                $response->setContent(json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            Log::warning('[VendorSafeResponse] guard skipped: ' . $e->getMessage());
        }
        return $response;
    }

    /** @param array<string,mixed>|list<mixed> $node */
    private function walk(array $node, ?string $parentKey, array &$hits): array
    {
        foreach ($node as $k => $v) {
            $key = is_string($k) ? strtolower($k) : $parentKey;   // list items inherit the key they sit under (errors: [...])
            if (is_array($v)) {
                $node[$k] = $this->walk($v, is_string($k) ? $key : $parentKey, $hits);
            } elseif (is_string($v) && $key !== null && in_array($key, self::KEYS, true) && self::exposes($v)) {
                $hits[] = mb_substr($v, 0, 160);
                $node[$k] = self::GENERIC;
            }
        }
        return $node;
    }

    /** True when a string both reads as an error and names a vendor (or carries a raw upstream payload). */
    public static function exposes(string $s): bool
    {
        if (! preg_match(self::VENDORS, $s)) { return false; }
        return (bool) preg_match(self::ERRORISH, $s);
    }

    /** For callers that build a sentence themselves: the customer-safe version of any raw provider text. */
    public static function sanitize(string $s): string
    {
        return self::exposes($s) ? self::GENERIC : $s;
    }
}
