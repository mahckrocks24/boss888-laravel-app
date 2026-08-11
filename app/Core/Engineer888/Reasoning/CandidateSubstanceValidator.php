<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * Is there an implementation in here, or only a description of one?
 *
 * WHY THIS EXISTS. On 2026-08-10 a candidate for a whole bug tracker arrived as
 * 210 bytes across five files. The entire implementation was:
 *
 *     class BugController extends Controller { [CRUD methods] }
 *     <!-- HTML for listing bugs -->
 *
 * CandidateValidator refused it, but only incidentally: the .php entries did not
 * open with `<?php`, so `not_php` fired. Add the opening tag and the same empty
 * proposal passes every structural rule and reaches a human as a candidate.
 *
 * WHAT THIS DELIBERATELY IS NOT. It is not a code-quality score, a linter, or a
 * judgement about whether the implementation is any good. That is the reviewer's
 * job and it is not mechanisable. This answers one narrow question — is there
 * executable substance at all — and stays silent on everything else.
 *
 * TWO EARLIER VERSIONS ARE WORTH KNOWING ABOUT, because both were wrong in ways
 * that only measurement revealed:
 *
 *   1. Counting executable statements refused
 *      `final class NotFoundException extends \RuntimeException {}`, which is
 *      complete, correct PHP. It broke twelve existing tests. Removed.
 *
 *   2. A list of stub phrases ("todo", "implementation here", …) was walked
 *      straight through on 2026-08-11 by
 *      `public function validate() { // Implement validation logic }`
 *      because the list held "implementation" but not the imperative
 *      "Implement X". Every stub phrase is one paraphrase away from a stub
 *      phrase nobody listed, so the question is now inverted — see
 *      DELIBERATELY_EMPTY.
 */
final class CandidateSubstanceValidator
{
    /**
     * Phrases that are never legitimate in final file content.
     *
     * These are elisions: the model telling a human "the rest goes here". The
     * content field replaces the file byte for byte, so an elision does not
     * produce a smaller change, it produces a broken file.
     */
    public const ELISIONS = [
        'existing code',
        'rest unchanged',
        'rest of the file unchanged',
        'remaining code unchanged',
        'unchanged lines omitted',
        'code omitted',
        'snip',
    ];

    /**
     * The ONLY comment bodies that mean a construct is deliberately empty.
     *
     * An allowlist rather than a stub list, for the reason in the class docblock:
     * a body that is nothing but a comment is a placeholder UNLESS it says, in so
     * many words, that it is empty on purpose. That list is short, closed and
     * unambiguous, which is exactly what a list of stub phrasings can never be.
     */
    public const DELIBERATELY_EMPTY = [
        'no-op', 'noop', 'no op', 'intentionally empty', 'deliberately empty',
        'intentionally blank', 'nothing to do', 'nothing to clean up', 'not needed',
        'unused', 'by design', 'left empty on purpose', 'required by the interface',
        'inherited behaviour is correct',
    ];

    /**
     * Write calls whose return value decides whether the data survived.
     *
     * Each of these returns false on failure and emits a PHP warning nobody
     * reads. A statement that calls one and discards the answer cannot report
     * failure, so the caller carries on and the user is told everything worked.
     */
    public const UNCHECKED_WRITES = [
        'file_put_contents', 'mkdir', 'rename', 'copy', 'unlink', 'fwrite', 'touch',
    ];

    /**
     * @return array<int,array{rule:string,detail:string}>
     */
    public function violations(string $path, string $content): array
    {
        $out = [];

        foreach ($this->uncheckedWrites($path, $content) as $found) { $out[] = $found; }

        foreach ($this->elisions($content) as $phrase) {
            $out[] = ['rule' => 'elided_content',
                      'detail' => 'content contains "' . $phrase . '". The content field replaces the file '
                                . 'byte for byte, so an elision produces a broken file, not a smaller change'];
        }

        if ($this->isPhp($path)) {
            $out = array_merge($out, $this->phpViolations($path, $content));
        } elseif ($this->isMarkup($path)) {
            $out = array_merge($out, $this->markupViolations($content));
        }

        return $out;
    }

    /**
     * A persistence call standing alone as a statement, with nothing reading it.
     *
     * WHY THIS IS A GATE AND NOT ADVICE. The standard "a write path creates what
     * it needs, or fails where the user can see it" was registered on 2026-08-11
     * with its reproduction attached, and the very next runs honoured it twice
     * and ignored it once: candidate 99b22fb9 on a clean clone returned HTTP 200,
     * lost the record, and left three file_put_contents warnings in the server
     * log. A rule followed two times in three is not a rule.
     *
     * Deliberately narrow. It fires only when the call IS the whole statement —
     * `file_put_contents($p, $j);` — and never when the result is assigned,
     * returned, tested, negated or thrown on. Checking the answer is the entire
     * ask; what the caller then does with it is the caller's business.
     *
     * Tests are exempt: a fixture that writes its own scratch file and would
     * fail visibly anyway is not the failure mode this exists for.
     *
     * @return array<int,array{rule:string,detail:string}>
     */
    private function uncheckedWrites(string $path, string $content): array
    {
        if (! str_ends_with($path, '.php') || $this->isTest($path)) { return []; }

        // A STATEMENT BOUNDARY, NOT A LINE START.
        //
        // Keying on the start of a line missed `function f(){ mkdir($d); }`,
        // where the call is a whole statement that happens to share its line.
        // The call is unchecked when it directly follows a `;`, a `{`, a `}` or
        // the start of the file: anything else — `= `, `return `, `if (`, `! `,
        // `throw ` — is something reading the answer.
        $out = [];
        $stripped = preg_replace(['~/\*.*?\*/~s', '~//[^
]*~', '~^\s*#[^
]*~m'], '', $content) ?? $content;

        foreach (self::UNCHECKED_WRITES as $fn) {
            $pattern = '/(?:^|[;{}])\s*@?' . preg_quote($fn, '/') . '\s*\(/';
            if (preg_match_all($pattern, $stripped, $m, PREG_OFFSET_CAPTURE) < 1) { continue; }

            $line = substr_count($stripped, "\n", 0, $m[0][0][1]) + 1;
            $out[] = ['rule' => 'unchecked_write',
                      'detail' => $fn . '() near line ' . $line . ' is called as a statement and its result is '
                                . 'discarded. It returns false when the write fails — usually because the '
                                . 'directory is not there on a fresh checkout — and a caller that does not look '
                                . 'reports success while the data is gone'];
        }

        return $out;
    }

    // ── PHP ─────────────────────────────────────────────────────────────────

    /** @return array<int,array{rule:string,detail:string}> */
    private function phpViolations(string $path, string $content): array
    {
        if (! str_contains($content, '<?php')) {
            return [];   // CandidateValidator::not_php owns that refusal
        }

        $tokens = @token_get_all($content);
        if ($tokens === false || $tokens === []) {
            return [];   // unparseable is somebody else's refusal, not ours
        }

        $declares = false;
        foreach ($tokens as $token) {
            if (! is_array($token)) { continue; }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION], true)) { $declares = true; }
            if (defined('T_ENUM') && $token[0] === T_ENUM) { $declares = true; }
        }

        $violations = [];

        foreach ($this->placeholderBodies($content) as $name) {
            $violations[] = ['rule' => 'placeholder_body',
                             'detail' => $name . ' has a body whose only content is a comment. A method that '
                                       . 'describes what it should do is not an implementation of it; if it is '
                                       . 'empty on purpose, say so in the comment'];
        }

        if ($this->isTest($path) && $declares) {
            if ($this->testMethodCount($tokens) > 0 && ! $this->hasAssertion($content)) {
                $violations[] = ['rule' => 'test_without_assertions',
                                 'detail' => 'declares test methods but makes no assertion, so it cannot fail '
                                           . 'and proves nothing'];
            }
        }

        return $violations;
    }

    /**
     * Function, method and class bodies whose entire content is a comment.
     *
     * @return array<int,string>
     */
    private function placeholderBodies(string $content): array
    {
        $out = [];
        $commentOnly = '(?:\/\/[^\n]*|\#[^\n]*|\/\*.*?\*\/|\s)*';

        $functionPattern = '/\bfunction\s+(\w+)\s*\([^)]*\)\s*(?::\s*[^{;]+)?\{\s*(' . $commentOnly . ')\}/s';
        if (preg_match_all($functionPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $body = trim($m[2]);
                if ($body === '') { continue; }          // a genuinely empty body is not our call
                if ($this->looksLikeStub($body)) { $out[] = 'function ' . $m[1] . '()'; }
            }
        }

        $classPattern = '/\b(?:final\s+|abstract\s+)?class\s+(\w+)[^{]*\{\s*(' . $commentOnly . ')\}/s';
        if (preg_match_all($classPattern, $content, $classMatches, PREG_SET_ORDER)) {
            foreach ($classMatches as $m) {
                $body = trim($m[2]);
                if ($body === '') { continue; }
                if ($this->looksLikeStub($body)) { $out[] = 'class ' . $m[1]; }
            }
        }

        return $out;
    }

    /** A comment-only body is a placeholder unless it declares itself empty on purpose. */
    private function looksLikeStub(string $commentBody): bool
    {
        $text = preg_replace('~^\s*(?://|\#|/\*+|\*+/?)~m', ' ', $commentBody) ?? $commentBody;
        $text = str_replace('*/', ' ', $text);
        $text = strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));

        if ($text === '') { return false; }

        foreach (self::DELIBERATELY_EMPTY as $phrase) {
            if (str_contains($text, $phrase)) { return false; }
        }

        return true;
    }

    private function testMethodCount(array $tokens): int
    {
        $count = 0;
        $expectName = false;

        foreach ($tokens as $token) {
            if (! is_array($token)) { continue; }
            if ($token[0] === T_FUNCTION) { $expectName = true; continue; }
            if ($expectName && $token[0] === T_STRING) {
                if (str_starts_with(strtolower($token[1]), 'test')) { $count++; }
                $expectName = false;
            }
        }

        return $count;
    }

    private function hasAssertion(string $content): bool
    {
        return preg_match('/\b(assert\w*|expect|shouldReceive|willReturn|self::assert|static::assert)\s*\(/i', $content) === 1
            || str_contains($content, '->fail(');
    }

    // ── MARKUP ──────────────────────────────────────────────────────────────

    /** @return array<int,array{rule:string,detail:string}> */
    private function markupViolations(string $content): array
    {
        $stripped = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;
        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $stripped) ?? $stripped;
        $stripped = trim($stripped);

        if ($stripped === '') {
            return [['rule' => 'markup_without_content',
                     'detail' => 'contains no markup at all once comments are removed — a comment describing '
                               . 'a page is not a page']];
        }

        return [];
    }

    // ── shape ───────────────────────────────────────────────────────────────

    private function isPhp(string $path): bool
    {
        return str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php');
    }

    private function isMarkup(string $path): bool
    {
        foreach (['.html', '.htm', '.blade.php', '.vue', '.svelte'] as $ext) {
            if (str_ends_with($path, $ext)) { return true; }
        }

        return false;
    }

    private function isTest(string $path): bool
    {
        return str_ends_with($path, 'Test.php')
            || str_starts_with($path, 'tests/')
            || str_contains($path, '/tests/');
    }

    /** @return array<int,string> */
    private function elisions(string $content): array
    {
        $lower = strtolower($content);
        $found = [];

        foreach (self::ELISIONS as $phrase) {
            // Only when it appears inside an ellipsis or a comment marker, which
            // is how an elision is always written. "existing code" in prose about
            // the change is not an elision.
            if (preg_match('/(\.\.\.|…|\/\/|\#|\/\*|<!--)\s*[^\n]{0,20}' . preg_quote($phrase, '/') . '/i', $lower) === 1) {
                $found[] = $phrase;
            }
        }

        return array_values(array_unique($found));
    }
}
