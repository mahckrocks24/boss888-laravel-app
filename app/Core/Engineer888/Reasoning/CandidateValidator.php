<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Coordination\OwnershipManifest;

/**
 * Engineer888 judging the model's output. Never the other way round.
 *
 * The validator answers one question — "is this the shape of engineering output
 * we asked for?" — and answers it structurally. It does not assess whether the
 * proposed code is a good idea; that is what VERIFY, the test suite and a human
 * approver are for. Confusing the two would let a well-formed proposal acquire
 * the appearance of a reviewed one.
 *
 * Fail closed. Any violation rejects the whole candidate. A partially valid
 * proposal is the most dangerous kind, because the invalid part is the part
 * nobody looked at.
 */
final class CandidateValidator
{
    /**
     * Paths no proposal may write, whatever it claims to be doing.
     *
     * These are not "risky" paths, they are paths where a write would defeat a
     * control: credentials, the installed dependency tree, git's own state, the
     * governed test configuration that decides which database a run touches
     * (INC-2026-006), and the backup directory that makes rollback possible.
     */
    public const FORBIDDEN_PREFIXES = [
        '.env', '.git/', 'vendor/', 'node_modules/', 'storage/', 'bootstrap/cache/',
        '.engineer888/', 'public/build/',
    ];

    /**
     * The controls that decide what Engineer888 is allowed to do.
     *
     * A system that can widen its own access has no access control, and a
     * reasoning provider asked to "fix the permission problem" would propose
     * exactly that in good faith. These paths are refused before installation,
     * not caught afterwards by review.
     *
     * Includes the tests: a candidate that quietly deletes the assertion is the
     * same defect as one that edits the policy.
     */
    public const PROTECTED_POLICY_PATHS = [
        'app/Core/Engineer888/Access/',
        'app/Http/Middleware/RequireEngineer888Access.php',
        'routes/api/admin/engineer888.php',
        'tests/Feature/Engineer888/AccessEnforcementTest.php',
        'database/migrations/2026_08_03_040000_create_engineer888_access_audit.php',
        'config/engineer888_access.php',
        'app/Http/Middleware/DenyApiKeyAuth.php',
        'app/Http/Middleware/RequireMfaStepUp.php',
        'app/Http/Middleware/RequireEnrolledMfa.php',
        'app/Core/Engineer888/Approval/',
        'tests/Feature/Engineer888/AccessBoundaryTest.php',
        'database/migrations/2026_08_03_020000_create_engineer888_access_grants.php',
    ];

    public const FORBIDDEN_PATTERNS = [
        '/^phpunit.*\.xml$/',            // decides the test database
        '/\.(sql|dump|gz|zip|tar)$/i',   // data, not source
        '/(^|\/)\.[^\/]*$/',             // dotfiles at any level
    ];

    public const ALLOWED_ACTIONS = ['create', 'update'];

    public function __construct(
        private readonly int $maxFiles = 12,
        private readonly int $maxFileBytes = 60000,
        // Optional so every existing caller keeps working. Without a
        // repository the coverage rules that need the filesystem —
        // does this existing test exist, is this test path ours — are
        // skipped rather than guessed at.
        private readonly ?string $repoPath = null,
    ) {}

    /**
     * The same rules, resolved against a different repository.
     *
     * WHY THIS EXISTS. Until E1-G the validator was built once, against
     * base_path(), and every candidate for every project was judged against
     * Engineer888's own tree. That is invisible while there is one project and
     * incoherent the moment there are two: in E1-F the sandbox was told by
     * SourceGrounding that tests/GreeterTest.php existed — because it does, in
     * the sandbox — and then refused for citing it, because it does not exist
     * here. The engine contradicted itself, and the model was right both times.
     *
     * The repository a candidate is judged against must be the repository the
     * candidate is for. Returned as a new instance so the shared validator
     * cannot acquire a project.
     */
    public function withRepository(?string $repoPath): self
    {
        return new self($this->maxFiles, $this->maxFileBytes, $repoPath);
    }

    /**
     * @return array<int,array{rule:string,detail:string}> empty means valid
     */
    public function violations(array $payload): array
    {
        $violations = [];

        foreach (CandidateImplementation::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                $violations[] = ['rule' => 'missing_section', 'detail' => $key . ' was not returned'];
            }
        }
        if ($violations !== []) {
            return $violations;   // no point checking the contents of a wrong shape
        }

        // Prose sections must say something or say UNKNOWN. An empty string is
        // neither an answer nor an admission, and reads as an answer downstream.
        foreach (['problem_understanding', 'implementation_strategy', 'testing_strategy', 'rollback'] as $key) {
            $value = $payload[$key];
            if (! is_string($value) || trim($value) === '') {
                $violations[] = ['rule' => 'unjustified_blank',
                                 'detail' => $key . ' is empty; anything that cannot be justified must be the string UNKNOWN'];
            }
        }

        foreach (['assumptions', 'unknowns', 'files_affected', 'risks', 'file_changes'] as $key) {
            if (! is_array($payload[$key])) {
                $violations[] = ['rule' => 'wrong_type', 'detail' => $key . ' must be a list'];
            }
        }

        if (! is_array($payload['migrations']) || ! array_key_exists('required', $payload['migrations'])) {
            $violations[] = ['rule' => 'wrong_type', 'detail' => 'migrations must declare a required flag (true, false or UNKNOWN)'];
        }

        $confidence = is_string($payload['confidence']) ? strtolower($payload['confidence']) : '';
        if (! in_array($confidence, CandidateImplementation::CONFIDENCE_LEVELS, true)) {
            $violations[] = ['rule' => 'bad_confidence',
                             'detail' => 'confidence must be one of ' . implode(', ', CandidateImplementation::CONFIDENCE_LEVELS)];
        }

        if ($violations !== []) {
            return $violations;
        }

        // Unknowns must be answerable questions, not a shrug.
        foreach ($payload['unknowns'] as $index => $unknown) {
            if (! is_array($unknown) || ! isset($unknown['question']) || trim((string) $unknown['question']) === '') {
                $violations[] = ['rule' => 'empty_unknown',
                                 'detail' => "unknowns[{$index}] does not state a question, so nobody can resolve it"];
            }
        }

        $changes = $payload['file_changes'];
        if (count($changes) > $this->maxFiles) {
            $violations[] = ['rule' => 'too_many_files',
                             'detail' => count($changes) . ' files proposed; the limit is ' . $this->maxFiles
                                       . '. A change this wide should be split into tasks that can be verified separately'];
        }

        $seen = [];
        foreach ($changes as $index => $change) {
            $label = "file_changes[{$index}]";

            if (! is_array($change) || ! isset($change['path']) || ! is_string($change['path'])) {
                $violations[] = ['rule' => 'bad_change', 'detail' => $label . ' has no path'];
                continue;
            }

            $path = $change['path'];

            foreach ($this->pathViolations($path) as $detail) {
                $violations[] = ['rule' => 'unsafe_path', 'detail' => $label . ' ' . $detail];
            }

            if (isset($seen[$path])) {
                $violations[] = ['rule' => 'duplicate_path',
                                 'detail' => $label . " proposes {$path} a second time; which one wins is undefined"];
            }
            $seen[$path] = true;

            $action = strtolower((string) ($change['action'] ?? ''));
            if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
                $violations[] = ['rule' => 'bad_action',
                                 'detail' => $label . " action must be create or update, got '{$action}'"];
            }

            if (! isset($change['content']) || ! is_string($change['content']) || $change['content'] === '') {
                $violations[] = ['rule' => 'empty_content',
                                 'detail' => $label . ' has no content; a truncated proposal must not be installable'];
                continue;
            }

            if (strlen($change['content']) > $this->maxFileBytes) {
                $violations[] = ['rule' => 'oversized_content',
                                 'detail' => $label . ' is ' . strlen($change['content']) . ' bytes, over the '
                                           . $this->maxFileBytes . ' byte limit'];
            }

            if (str_ends_with($path, '.php') && ! str_starts_with(ltrim($change['content']), '<?php')) {
                $violations[] = ['rule' => 'not_php',
                                 'detail' => $label . ' targets a .php file but the content does not open with <?php'];
                continue;
            }

            if (str_ends_with($path, '.php') && $this->leaksUnknownIntoCode($change['content'])) {
                $violations[] = ['rule' => 'unknown_leaked_into_code',
                                 'detail' => $label . ' uses UNKNOWN as a bare identifier in executable code. '
                                           . 'UNKNOWN is how a proposal admits it does not know something; written '
                                           . 'into a file it becomes an undefined constant that lints clean and '
                                           . 'throws at runtime'];
            }
        }

        // Tests are part of the implementation from Sprint 10. A change to
        // executable behaviour that arrives without coverage is incomplete,
        // not merely unverified — INSUFFICIENT_EVIDENCE at VERIFY punished a
        // candidate for a gap nobody had asked it to fill.
        if ($this->repoPath !== null && $violations === []) {
            // A path that does not resolve cannot answer "does this test exist"
            // or "do we own it". Skipping those checks would let a candidate for
            // a missing repository validate more easily than one for a real
            // repository, which is the wrong way round.
            $root = realpath($this->repoPath);

            if ($root === false || ! is_dir($root)) {
                return [['rule' => 'unresolvable_repository',
                         'detail' => 'the project repository ' . $this->repoPath . ' does not resolve on '
                                   . 'this machine, so coverage and ownership cannot be checked against '
                                   . 'it. A candidate is not validated against a repository that is not there.']];
            }

            $changeSet = [];
            foreach ($changes as $change) {
                if (isset($change['path'], $change['content'])) {
                    $changeSet[(string) $change['path']] = (string) $change['content'];
                }
            }

            foreach (TestContract::violations($payload, $changeSet, $this->repoPath) as $violation) {
                $violations[] = $violation;
            }

            foreach (TestContract::ownershipViolations(array_keys($changeSet),
                OwnershipManifest::active($this->repoPath)) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Did the proposal write its own uncertainty marker into the code?
     *
     * OBSERVED IN PRODUCTION, 2026-08-02. A provider that could not determine
     * two array values emitted `'model' => UNKNOWN` — honest about its
     * ignorance and catastrophic as PHP: a bare undefined constant that `php -l`
     * accepts and that throws the moment the line runs.
     *
     * Tokenised, so the word is only a violation where it is an identifier.
     * `self::UNKNOWN`, `$x->UNKNOWN`, a comment or a string are all legitimate.
     */
    private function leaksUnknownIntoCode(string $content): bool
    {
        $tokens = @token_get_all($content);
        if (! is_array($tokens)) { return false; }

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== CandidateImplementation::UNKNOWN) {
                continue;
            }

            // Preceded by :: or -> means it is a member, which is fine.
            for ($i = $index - 1; $i >= 0; $i--) {
                $prev = $tokens[$i];
                if (is_array($prev) && $prev[0] === T_WHITESPACE) { continue; }
                if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR,
                                                           T_NULLSAFE_OBJECT_OPERATOR, T_CONST], true)) {
                    continue 2;
                }
                break;
            }

            // Followed by ( means a function call; followed by => in a
            // declaration context would be a key. Anything else is a constant.
            for ($i = $index + 1, $n = count($tokens); $i < $n; $i++) {
                $next = $tokens[$i];
                if (is_array($next) && $next[0] === T_WHITESPACE) { continue; }
                if ($next === '(') { continue 2; }
                break;
            }

            return true;
        }

        return false;
    }

    /** @return array<int,string> */
    private function pathViolations(string $path): array
    {
        $problems = [];

        if ($path === '' || trim($path) !== $path) {
            $problems[] = 'path is empty or padded with whitespace';
        }
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            $problems[] = 'path is absolute; every write is relative to the repository root';
        }
        if (str_contains($path, '..')) {
            $problems[] = 'path traverses upward, which could escape the repository';
        }
        if (str_contains($path, "\0")) {
            $problems[] = 'path contains a null byte';
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $problems[] = "writes under {$prefix}, which is never a reasoning output";
            }
        }

        foreach (self::PROTECTED_POLICY_PATHS as $protected) {
            if (str_starts_with($path, $protected)) {
                $problems[] = "is protected policy infrastructure ({$protected}). Engineer888 cannot "
                            . 'change what Engineer888 is permitted to do.';
            }
        }
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                $problems[] = 'matches a forbidden path pattern (' . $pattern . ')';
            }
        }

        return $problems;
    }
}
