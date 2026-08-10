<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * Is there an implementation in here, or only a description of one?
 *
 * WHY THIS EXISTS. On 2026-08-10 a candidate for a whole bug tracker arrived as
 * 210 bytes across five files. The entire implementation was:
 *
 *     class BugController extends Controller { /* CRUD methods *​/ }
 *     <!-- HTML for listing bugs -->
 *
 * CandidateValidator refused it, but only incidentally: the .php entries did not
 * open with `<?php`, so `not_php` fired. Had the model written
 * `<?php class BugController { /* CRUD *​/ }` the same empty proposal would have
 * passed every rule and been offered to a human as a reviewable candidate.
 *
 * WHAT THIS DELIBERATELY IS NOT. It is not a code-quality score, a linter, or a
 * judgement about whether the implementation is any good. That is the reviewer's
 * job and it is not mechanisable. This answers one narrow question — is there
 * executable substance at all — and stays silent on everything else. A rule that
 * refuses real work is far more expensive than one that misses a stub, so every
 * check below is written to fail towards ACCEPTING.
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
     * Comment bodies that announce an absence of implementation.
     *
     * Matched only when such a comment is the ENTIRE body of a construct — see
     * bodyIsPlaceholder(). A TODO inside a working method is a normal note and
     * must not be refused.
     */
    public const STUB_WORDS = [
        'crud', 'crud methods', 'implementation', 'implementation here', 'implement later',
        'todo', 'fixme', 'tbd', 'placeholder', 'your code here', 'add logic here',
        'logic here', 'code here', 'fill in', 'to be implemented', 'not implemented yet',
        'html for', 'html form', 'form for', 'markup for', 'view for', 'template for',
    ];

    /**
     * @return array<int,array{rule:string,detail:string}>
     */
    public function violations(string $path, string $content): array
    {
        $out = [];

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

        // COUNTING STATEMENTS WAS THE WRONG TEST, AND IT WAS TRIED FIRST.
        //
        // The first version of this class refused any file that declared a class
        // or function without an executable statement. It caught the acceptance
        // stub — and it also caught
        //
        //     final class NotFoundException extends \RuntimeException {}
        //
        // which is complete, correct, idiomatic PHP. It broke twelve existing
        // Engineer888 tests whose fixtures are deliberately minimal. A rule that
        // refuses real work costs far more than one that misses a stub, so the
        // statement count is gone and the question is asked structurally
        // instead: is the BODY of something a placeholder comment?
        //
        // That still refuses every file of candidate 86cc830a and lets a marker
        // class through, which is the correct pair of answers.
        $declares = false;

        foreach ($tokens as $token) {
            if (! is_array($token)) { continue; }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION], true)) { $declares = true; }
            if (defined('T_ENUM') && $token[0] === T_ENUM) { $declares = true; }
        }

        $violations = [];

        foreach ($this->placeholderBodies($content) as $name) {
            $violations[] = ['rule' => 'placeholder_body',
                             'detail' => $name . ' has a body whose only content is a placeholder comment'];
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
     * Function/method bodies whose entire content is a stub comment.
     *
     * Deliberately textual and narrow: it looks for `{ <comment only> }` where
     * the comment says one of STUB_WORDS. A body with any statement in it, or a
     * comment that says something specific, is left alone.
     *
     * @return array<int,string>
     */
    private function placeholderBodies(string $content): array
    {
        $out = [];
        $pattern = '/\bfunction\s+(\w+)\s*\([^)]*\)\s*(?::\s*[^{;]+)?\{\s*((?:\/\/[^\n]*|#[^\n]*|\/\*.*?\*\/|\s)*)\}/s';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return $out;
        }

        foreach ($matches as $m) {
            $body = trim($m[2]);
            if ($body === '') { continue; }          // a genuinely empty method is not our call
            if ($this->looksLikeStub($body)) { $out[] = 'function ' . $m[1] . '()'; }
        }

        // The class-level case: `class X { /* CRUD methods */ }`
        $classPattern = '/\b(?:final\s+|abstract\s+)?class\s+(\w+)[^{]*\{\s*((?:\/\/[^\n]*|#[^\n]*|\/\*.*?\*\/|\s)*)\}/s';
        if (preg_match_all($classPattern, $content, $classMatches, PREG_SET_ORDER) !== false) {
            foreach ($classMatches as $m) {
                $body = trim($m[2]);
                if ($body === '') { continue; }
                if ($this->looksLikeStub($body)) { $out[] = 'class ' . $m[1]; }
            }
        }

        return $out;
    }

    private function looksLikeStub(string $commentBody): bool
    {
        // The comment markers are stripped with ~ as the delimiter: the pattern
        // has to contain a literal # for shell-style comments, and using # as the
        // delimiter as well made PCRE read the rest of the pattern as modifiers.
        $text = strtolower(trim(preg_replace('~^\s*(//|\#|/\*+|\*+/?)|\*+/\s*$~m', ' ', $commentBody) ?? ''));
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') { return false; }

        // Short and made only of stub vocabulary. Length matters: a body comment
        // that actually explains something is longer than a label.
        if (strlen($text) > 80) { return false; }

        foreach (self::STUB_WORDS as $word) {
            if (str_contains($text, $word)) { return true; }
        }

        return false;
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
        return preg_match('/\b(assert\w*|expect|shouldReceive|willReturn|->fail\(|self::assert|static::assert)\s*\(/i', $content) === 1;
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
            if (preg_match('/(\.\.\.|…|\/\/|#|\/\*|<!--)\s*[^\n]{0,20}' . preg_quote($phrase, '/') . '/i', $lower) === 1) {
                $found[] = $phrase;
            }
        }

        return array_values(array_unique($found));
    }
}
