<?php

namespace App\Engines\Studio\Readiness;

/**
 * STUDIO888 Phase L — deterministic sanitizer for the OBSERVED provider prompt.
 * Applies ONLY to the persisted observation copy; never touches the live prompt.
 * Redacts signed URLs, bearer tokens, API keys, credentials, and private paths.
 */
class PromptSanitizer
{
    private const PATTERNS = [
        '#https?://\S+?[?&](?:signature|sig|x-amz-signature|token|key|se)=\S+#i' => '[redacted-signed-url]',
        '#\bBearer\s+[A-Za-z0-9\-\._~\+/]+=*#i'                                  => '[redacted-bearer]',
        '#\bsk-[A-Za-z0-9]{16,}#'                                                => '[redacted-key]',
        '#\bAKIA[0-9A-Z]{16}\b#'                                                 => '[redacted-aws-key]',
        '#\b[A-Za-z0-9._%+-]+:[^@\s/]+@[A-Za-z0-9.-]+#'                          => '[redacted-cred]',
        '#(?:/var/www|/home/|/storage/app)/\S+#'                                => '[redacted-path]',
    ];

    /** @return array{text:string,redacted:bool} */
    public static function sanitize(string $prompt): array
    {
        $redacted = false;
        foreach (self::PATTERNS as $re => $rep) {
            $out = preg_replace($re, $rep, $prompt);
            if (is_string($out) && $out !== $prompt) {
                $redacted = true;
                $prompt = $out;
            }
        }
        return ['text' => $prompt, 'redacted' => $redacted];
    }
}
