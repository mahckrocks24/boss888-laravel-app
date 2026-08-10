<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Reasoning\CandidateSubstanceValidator;
use Tests\TestCase;

/**
 * Is there an implementation in the candidate, or only a description of one?
 *
 * Candidate 86cc830a proposed a whole bug tracker as 210 bytes:
 *
 *     class BugController extends Controller { /* CRUD methods *​/ }
 *     <!-- HTML for listing bugs -->
 *
 * CandidateValidator refused it, but only because the .php entries did not open
 * with `<?php`. Add the opening tag and the same empty proposal passes every
 * structural rule and reaches a human as a reviewable candidate.
 *
 * Every test below is paired: a stub that must be refused, and real work of the
 * same shape that must not be. A rule that refuses real work costs far more than
 * one that misses a stub.
 */
class CandidateSubstanceTest extends TestCase
{
    private CandidateSubstanceValidator $substance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->substance = new CandidateSubstanceValidator();
    }

    private function rules(string $path, string $content): array
    {
        return array_column($this->substance->violations($path, $content), 'rule');
    }

    // ── 18-22 · absence of implementation ───────────────────────────────────

    public function test_the_exact_candidate_that_failed_acceptance_is_refused(): void
    {
        $rules = $this->rules('app/Http/Controllers/BugController.php',
            "<?php\n\nclass BugController extends Controller { /* CRUD methods */ }\n");

        $this->assertContains('placeholder_body', $rules,
            'this is candidate 86cc830a with an opening tag added — it must not become acceptable');

        // And the narrowing that made this rule survivable must hold: a marker
        // class is complete PHP and must never be refused.
        $this->assertSame([], $this->rules('app/NotFoundException.php',
            "<?php\n\nnamespace App;\n\nfinal class NotFoundException extends \\RuntimeException {}\n"),
            'refusing a marker class is how the first version of this rule broke twelve tests');
    }

    public function test_a_class_whose_body_is_only_a_todo_is_refused(): void
    {
        $rules = $this->rules('app/BugRepository.php',
            "<?php\n\nnamespace App;\n\nfinal class BugRepository\n{\n    // TODO\n}\n");

        $this->assertContains('placeholder_body', $rules);
    }

    public function test_a_method_whose_body_is_only_a_placeholder_is_refused(): void
    {
        $rules = $this->rules('app/BugService.php', <<<'PHP'
<?php

namespace App;

final class BugService
{
    public function all(): array
    {
        return [];
    }

    public function create(array $input): void
    {
        /* implementation here */
    }
}
PHP);

        $this->assertContains('placeholder_body', $rules,
            'one real method does not license the next one being a comment');
    }

    public function test_placeholder_markup_is_refused(): void
    {
        $this->assertContains('markup_without_content',
            $this->rules('resources/views/bugs/index.blade.php', "<!-- HTML for listing bugs -->\n"));

        $this->assertContains('markup_without_content',
            $this->rules('public/index.html', "<!-- HTML form for creating a bug -->\n"));
    }

    public function test_an_elision_is_refused(): void
    {
        $this->assertContains('elided_content',
            $this->rules('app/Bug.php', "<?php\n\nclass Bug\n{\n    // ... existing code ...\n\n    public function x(): int { return 1; }\n}\n"));

        $this->assertContains('elided_content',
            $this->rules('app/Bug.php', "<?php\n\nclass Bug\n{\n    public function x(): int { return 1; }\n    // rest unchanged\n}\n"));
    }

    // ── 22 · tests must be able to fail ─────────────────────────────────────

    public function test_a_test_file_with_no_assertions_is_refused(): void
    {
        $rules = $this->rules('tests/BugTest.php', <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

class BugTest extends TestCase
{
    public function testItCreatesABug(): void
    {
        $bug = new \App\Bug('x');
        // checks that the bug is created
    }
}
PHP);

        $this->assertContains('test_without_assertions', $rules,
            'a test that cannot fail is a claim of coverage, not coverage');
    }

    // ── 23-25 · real work must pass ─────────────────────────────────────────

    public function test_a_real_php_implementation_is_accepted(): void
    {
        $rules = $this->rules('app/BugRepository.php', <<<'PHP'
<?php

namespace App;

/**
 * Bugs, stored as one JSON document.
 *
 * A file is the right store for a tool this size: no server to run, no schema to
 * migrate, and the whole dataset is inspectable with cat.
 */
final class BugRepository
{
    public function __construct(private readonly string $path) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function save(array $bug): void
    {
        $bugs = $this->all();
        $bugs[] = $bug;
        file_put_contents($this->path, json_encode($bugs, JSON_PRETTY_PRINT));
    }
}
PHP);

        $this->assertSame([], $rules, 'real work must not be refused: ' . json_encode($rules));
    }

    public function test_a_real_template_is_accepted(): void
    {
        $rules = $this->rules('public/bugs.html', <<<'HTML'
<!-- the bug list -->
<!doctype html>
<html lang="en">
  <body>
    <h1>Bugs</h1>
    <table><tr><th>Title</th><th>Severity</th></tr></table>
  </body>
</html>
HTML);

        $this->assertSame([], $rules);
    }

    public function test_meaningful_comments_do_not_cause_a_false_refusal(): void
    {
        $rules = $this->rules('app/Severity.php', <<<'PHP'
<?php

namespace App;

final class Severity
{
    // Ordered deliberately: comparisons rely on the index, so Critical must stay
    // last. A TODO here would be a note, not an absence of implementation.
    public const LEVELS = ['Low', 'Medium', 'High', 'Critical'];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::LEVELS, true);
    }
}
PHP);

        $this->assertSame([], $rules,
            'comments — including the word TODO in prose — must never be the reason a candidate is refused');
    }

    public function test_a_real_test_with_assertions_is_accepted(): void
    {
        $rules = $this->rules('tests/SeverityTest.php', <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function testItRejectsAnUnknownSeverity(): void
    {
        $this->assertFalse(\App\Severity::isValid('Catastrophic'));
    }
}
PHP);

        $this->assertSame([], $rules);
    }

    public function test_the_stub_candidate_is_refused_through_candidate_validator(): void
    {
        // The rules above prove the detector works. This proves it is WIRED —
        // a detector nobody calls would have let candidate 86cc830a through
        // exactly as before.
        $repo = sys_get_temp_dir() . '/e888-wire-' . bin2hex(random_bytes(4));
        mkdir($repo . '/app', 0775, true);

        $violations = (new \App\Core\Engineer888\Reasoning\CandidateValidator(12, 60000, $repo))
            ->violations([
                'problem_understanding'   => 'x',
                'assumptions'             => [],
                'unknowns'                => [],
                'implementation_strategy' => 'x',
                'files_affected'          => [],
                'migrations'              => ['required' => false, 'detail' => 'none'],
                'risks'                   => [],
                'testing_strategy'        => 'x',
                'rollback'                => 'x',
                'confidence'              => 'high',
                'file_changes'            => [[
                    'path' => 'app/BugController.php', 'action' => 'create',
                    'content' => "<?php\n\nclass BugController extends Controller { /* CRUD methods */ }\n",
                ]],
            ]);

        $this->assertContains('placeholder_body', array_column($violations, 'rule'),
            'CandidateValidator must carry the substance check, not merely be able to');

        @rmdir($repo . '/app'); @rmdir($repo);
    }

    public function test_an_interface_is_not_mistaken_for_an_empty_implementation(): void
    {
        $rules = $this->rules('app/BugStore.php', <<<'PHP'
<?php

namespace App;

interface BugStore
{
    public function all(): array;

    public function save(array $bug): void;
}
PHP);

        $this->assertSame([], $rules, 'method signatures are the whole point of an interface');
    }
}
