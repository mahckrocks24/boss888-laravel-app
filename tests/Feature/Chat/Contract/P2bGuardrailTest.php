<?php

namespace Tests\Feature\Chat\Contract;

use App\Core\Chat\MessageChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * P2-B guardrails — the invariants that are about what must NOT happen.
 * INV-13 (Studio stays free), INV-14 (Aria stays ephemeral), INV-15 (contract frozen).
 */
class P2bGuardrailTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function routes(): string
    {
        return \Tests\Support\RouteSource::all();
    }

    // ── INV-13 · Studio billing freeze ───────────────────────────────────────

    /** @test The dead guard must remain exactly as it is — repairing it would begin billing. */
    public function the_studio_dead_credit_guard_is_left_untouched(): void
    {
        $this->assertStringContainsString(
            'class_exists(\App\Services\CreditService::class)',
            $this->routes(),
            'the Studio class_exists() guard was altered — repairing it starts charging a free feature (CR-23 is a pricing decision)'
        );
    }

    /** @test The service that guard names still does not exist. */
    public function the_referenced_credit_service_still_does_not_exist(): void
    {
        $this->assertFalse(class_exists('App\Services\CreditService'),
            'App\Services\CreditService now exists — Studio would start charging silently');
        $this->assertTrue(class_exists('App\Core\Billing\CreditService'),
            'the authoritative CreditService must still exist');
    }

    /** @test No Studio surface may be classified chargeable. */
    public function no_studio_surface_is_chargeable(): void
    {
        $svc = app(MessageChargeService::class);
        foreach (['s5_studio_chat', 's6_studio_ai', 's7_builder_arthur'] as $s) {
            $this->assertFalse($svc->classify($s)['chargeable'], "{$s} must remain uncharged");
        }
    }

    /** @test Production has never charged studio_chat, and still has not. */
    public function no_studio_charge_exists_in_the_ledger(): void
    {
        $n = DB::table('message_charges')
            ->whereIn('surface', ['s5_studio_chat', 's6_studio_ai', 's7_builder_arthur'])
            ->where('status', 'committed')->count();

        $this->assertSame(0, $n, 'a Studio charge was committed — INV-13 violated');
    }

    // ── INV-14 · Aria stays ephemeral ────────────────────────────────────────

    /** @test P2-B must not have created an Aria message store. */
    public function no_aria_message_store_exists(): void
    {
        foreach (['aria_messages', 'aria_conversations', 'assistant_messages', 'ai_assistant_messages'] as $t) {
            $this->assertFalse(Schema::hasTable($t), "an Aria store ({$t}) was created — INV-14 violated");
        }
    }

    /** @test The Aria handler still persists nothing, and says why. */
    public function the_aria_handler_still_persists_nothing(): void
    {
        $src = $this->routes();
        $pos = strpos($src, "Route::post('/assistant', function");
        $this->assertNotFalse($pos, 'the Aria route moved; this guard must be re-derived before it is trusted');

        $block = substr($src, $pos, 9000);
        $this->assertDoesNotMatchRegularExpression(
            "/DB::table\(\s*'(aria|assistant)_?\w*messages?'\s*\)->insert/i",
            $block,
            'the Aria handler now persists messages — INV-14 says it must remain client-held during P2-B'
        );
        $this->assertStringContainsString('CR-04 (Aria half) and deferred to P2-B', $src,
            'the note explaining why Aria cannot persist must remain');
    }

    // ── INV-15 · contract frozen ─────────────────────────────────────────────

    /** @test The contract version is still 1.0. */
    public function the_chat_contract_version_is_still_1_0(): void
    {
        $path = base_path('contracts/chat/v1/error.schema.json');
        $this->assertFileExists($path);
        $schema = json_decode((string) file_get_contents($path), true);

        $this->assertContains('CHAT_CONVERSATION_CONFLICT', $schema['properties']['code']['enum'],
            'P2-B relies on CHAT_CONVERSATION_CONFLICT already existing in v1 — if it is absent the contract would need a bump');
        $this->assertContains('CHAT_INSUFFICIENT_CREDITS', $schema['properties']['code']['enum']);
        $this->assertContains('CHAT_PROVIDER_UNAVAILABLE', $schema['properties']['code']['enum']);
        $this->assertContains('CHAT_INTERNAL_ERROR', $schema['properties']['code']['enum']);
    }

    /** @test No contracts/chat/v2 directory was created. */
    public function no_v2_contract_directory_was_created(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('contracts/chat/v2'),
            'a v2 contract directory appeared — P2-B must not change the contract version');
    }

    /** @test Every error code P2-B can emit is already in the v1 taxonomy. */
    public function every_p2b_error_code_is_from_the_existing_taxonomy(): void
    {
        $schema = json_decode((string) file_get_contents(base_path('contracts/chat/v1/error.schema.json')), true);
        $allowed = $schema['properties']['code']['enum'];

        // Configuration keys are not error codes. CHAT_IDEMPOTENCY_ENABLED and
        // CHAT_IDEMPOTENCY_SURFACES are env var names read via env(); matching
        // them would report a taxonomy violation that does not exist.
        $notErrorCodes = ['CHAT_IDEMPOTENCY_ENABLED', 'CHAT_IDEMPOTENCY_SURFACES'];

        $emitted = [];
        foreach (glob(app_path('Core/Chat/*.php')) as $f) {
            preg_match_all("/'(CHAT_[A-Z_]+)'/", (string) file_get_contents($f), $m);
            $emitted = array_merge($emitted, $m[1]);
        }
        $emitted = array_values(array_diff(array_unique($emitted), $notErrorCodes));
        $this->assertNotEmpty($emitted, 'expected the coordinator to emit taxonomy codes');

        foreach ($emitted as $code) {
            $this->assertContains($code, $allowed,
                "{$code} is not in the frozen v1 taxonomy — emitting it would require a contract version bump (INV-15)");
        }
    }

    // ── the additive schema is genuinely additive ────────────────────────────

    /** @test P2-B added no column to any existing message store. */
    public function existing_message_stores_were_not_altered(): void
    {
        $expected = [
            'agent_messages'         => ['id','workspace_id','agent_slug','sender','content','role','metadata_json','read_at','created_at','updated_at'],
            'seo_assistant_messages' => ['id','workspace_id','user_id','role','content','action_proposed_json','meta_json','created_at'],
            'chatbot_messages'       => ['id','session_id','workspace_id','role','content','intent','classifier_source','kb_hits','credits_used','meta_json','created_at'],
        ];

        foreach ($expected as $table => $cols) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $actual = Schema::getColumnListing($table);
            sort($actual);
            $want = $cols;
            sort($want);
            $this->assertSame($want, $actual, "{$table} was altered — P2-B is additive-only");
        }
    }

    /** @test The credit ledger keeps its own shape and remains authoritative. */
    public function the_credit_ledger_shape_is_unchanged(): void
    {
        $cols = Schema::getColumnListing('credit_transactions');
        foreach (['workspace_id', 'type', 'amount', 'reservation_reference', 'reservation_status'] as $c) {
            $this->assertContains($c, $cols, "credit_transactions.{$c} is missing — the ledger must remain authoritative");
        }
        $this->assertNotContains('message_charge_id', $cols,
            'the ledger must not gain a dependency on message_charges; the link lives on the charge side only');
    }
}
