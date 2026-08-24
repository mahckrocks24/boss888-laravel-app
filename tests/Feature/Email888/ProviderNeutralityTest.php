<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\EmailDispatcher;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\OutboundPolicy;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMAIL888 EM-8 — provider-neutral certification.
 *
 * The question is not "does Postmark work". It is: if the platform changed
 * provider tomorrow, how much code would have to be edited, and would anything
 * silently keep working while meaning something different?
 *
 * Two kinds of assertion here, and both are needed:
 *   STRUCTURAL — no vendor name appears where it should not (comments stripped
 *                with token_get_all(), so documentation may still explain the
 *                vendor without failing the guard)
 *   BEHAVIOURAL — reconfiguring the provider actually changes what the system
 *                records. A grep can pass while the seam is decorative.
 */
class ProviderNeutralityTest extends TestCase
{
    use RefreshDatabase;

    /** Files that form the canonical contract every producer touches. */
    private const CONTRACT = [
        'app/Core/Email888/Contracts/SendEmailCommand.php',
        'app/Core/Email888/Contracts/EmailResult.php',
        'app/Core/Email888/States/DeliveryState.php',
        'app/Core/Email888/OutboundPolicy.php',
        'app/Core/Email888/EmailDispatcher.php',
        'app/Core/Email888/DeliveryLedger.php',
    ];

    /**
     * Vendor names that must not appear in executable code outside adapters.
     *
     * Deliberately no bare 'ses': as a substring it matches "addresses",
     * "responses" and "cases", which would make this guard fire on ordinary
     * English and get "fixed" by weakening it. A needle short enough to hit
     * prose is not a guard.
     */
    private const VENDORS = ['postmark', 'migadu', 'sendgrid', 'mailgun', 'amazonses', 'sparkpost'];

    private function base(): string
    {
        return rtrim(base_path(), '/') . '/';
    }

    private function codeOf(string $rel): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($this->base() . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return strtolower($out);
    }

    // ── structural ────────────────────────────────────────────────────

    /** @test */
    public function the_canonical_contract_names_no_vendor(): void
    {
        foreach (self::CONTRACT as $rel) {
            $code = $this->codeOf($rel);

            foreach (self::VENDORS as $vendor) {
                $this->assertStringNotContainsString($vendor, $code,
                    "{$rel} names '{$vendor}' in executable code. A producer reading this contract "
                    . 'would inherit a dependency on one vendor.');
            }
        }
    }

    /** @test */
    public function the_command_exposes_no_provider_specific_field(): void
    {
        $rc = new \ReflectionClass(SendEmailCommand::class);

        $names = array_map(fn ($p) => strtolower($p->getName()), $rc->getConstructor()->getParameters());

        foreach (['messagestream', 'stream', 'servertoken', 'token', 'provider', 'apikey', 'server'] as $forbidden) {
            $this->assertNotContains($forbidden, $names,
                "SendEmailCommand exposes '{$forbidden}'. Producers declare intent; routing is policy.");
        }

        // stream_class is the NEUTRAL abstraction and is allowed — it names a
        // class of traffic, not a vendor's stream.
        $this->assertContains('streamclass', $names);
    }

    /** @test */
    public function the_delivery_state_vocabulary_is_provider_neutral(): void
    {
        foreach (DeliveryState::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $case->value);

            foreach (self::VENDORS as $vendor) {
                $this->assertStringNotContainsString($vendor, strtolower($case->value));
            }
        }

        // Provider event names ("Delivery", "SpamComplaint", "HardBounce") must
        // never become our vocabulary; product code branches on these instead.
        $values = array_map(fn ($c) => $c->value, DeliveryState::cases());

        foreach (['delivery', 'spamcomplaint', 'hardbounce', 'subscriptionchange'] as $providerWord) {
            $this->assertNotContains($providerWord, $values);
        }
    }

    /** @test */
    public function no_producer_outside_the_boundary_names_a_vendor(): void
    {
        // Where a vendor MAY be named. This list got SHORTER in EM-8, which is
        // the point of the exercise:
        //
        //   app/Core/Email888/Http/          removed - the webhook controller is
        //                                    now provider-agnostic and reads
        //                                    ProviderEvent, not a vendor payload
        //   .../FailureClassifier.php        removed - vendor error codes moved
        //                                    into the adapter
        //
        // What remains genuinely belongs to a vendor.
        $allowed = [
            'app/Core/Email888/Providers/',            // THE outbound adapter boundary
            'app/Core/Email888/Console/',              // commands calling one vendor's API
            'app/Connectors/Infrastructure/',          // Business Email white-label boundary
            'app/Connectors/EmailConnector.php',       // retired, documents what it was
            'app/Core/Email888/Listeners/',            // applies the vendor stream header
            'app/Http/Controllers/Api/Admin/',         // credential registry naming providers
            'app/Core/Admin/',
        ];

        $offenders = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base() . 'app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace($this->base(), '', $f->getPathname());

            foreach ($allowed as $prefix) {
                if (str_starts_with($rel, $prefix)) {
                    continue 2;
                }
            }

            $code = $this->codeOf($rel);

            foreach (['postmark', 'migadu'] as $vendor) {
                if (str_contains($code, $vendor)) {
                    $offenders[] = $rel . ' :: ' . $vendor;
                }
            }
        }

        sort($offenders);

        // ONE file, and it is a storage boundary rather than a producer.
        //
        // `postmark_message_id` is a live column on email_campaigns_log, created
        // in April 2026 and holding real data; renaming it is a migration with
        // real risk for a cosmetic gain. Two PRODUCERS used to write that name
        // directly — the campaign fan-out and the queued job, both places that
        // must not know who the provider is. The name is now confined to a
        // single documented constant, so the debt is visible and one line wide.
        $this->assertSame(
            ['app/Engines/Marketing/Support/CampaignLogColumns.php :: postmark'],
            $offenders,
            'A vendor name reappeared outside the provider boundary. If it is a legacy column, '
            . 'route it through CampaignLogColumns; if it is anything else, it does not belong.',
        );
    }

    // ── behavioural: the seam is real, not decorative ─────────────────

    /** @test */
    public function reconfiguring_the_provider_changes_what_the_ledger_records(): void
    {
        config(['email888.provider' => 'acme-post']);

        $this->assertSame('acme-post', OutboundPolicy::provider());

        app(EmailDispatcher::class)->send(new SendEmailCommand(
            purpose:    'notification',
            recipients: ['neutrality@example.com'],
            subject:    'EM-8 neutrality',
            html:       '<p>hello</p>',
        ));

        $row = EmailDelivery::latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('acme-post', $row->provider,
            'The ledger wrote a hardcoded vendor name. The seam is decorative — swapping providers '
            . 'would still require editing the canonical core.');
    }

    /** @test */
    public function the_provider_seam_refuses_to_be_blank(): void
    {
        // A blank provider would write ledger rows no reconciler could match
        // back to any vendor. Failing to a known value beats recording ''.
        config(['email888.provider' => '']);

        $this->assertNotSame('', OutboundPolicy::provider());
    }

    /** @test */
    public function the_stream_registry_maps_neutral_classes_to_vendor_names(): void
    {
        $streams = config('email888.streams');

        // The LEFT side is ours and must stay neutral; the right side is the
        // vendor's word for it and is the only place that word belongs.
        foreach (array_keys($streams) as $class) {
            $this->assertContains($class, ['transactional', 'broadcast'],
                "Stream class '{$class}' is not a neutral traffic class.");
        }

        foreach (config('email888.purposes') as $purpose => $policy) {
            $this->assertContains($policy['stream'], ['transactional', 'broadcast'],
                "Purpose '{$purpose}' names a vendor stream instead of a traffic class.");
        }
    }

    /**
     * @test
     *
     * FailureClassifier recognises vendor error codes, which is recorded rather
     * than pretended away. What matters for neutrality is that its OUTPUT is
     * neutral and that an unrecognised failure from ANY provider is treated as
     * retryable — the safe direction.
     */
    public function failure_classification_degrades_safely_for_an_unknown_provider(): void
    {
        $unknown = \App\Core\Email888\FailureClassifier::classify(
            new \RuntimeException('acme-post: upstream 503 shrug')
        );

        $this->assertTrue($unknown['retryable'],
            'An unrecognised provider failure was treated as permanent. That silently abandons '
            . 'real customer mail, which is the exact failure this subsystem exists to end.');
        $this->assertSame('transport_error', $unknown['category']);

        // And the categories it produces name no vendor.
        foreach (['recipient suppressed', 'invalid recipient', 'provider auth'] as $_) {
            // categories are snake_case neutral nouns; assert on a real one
        }

        $suppressed = \App\Core\Email888\FailureClassifier::classify(
            new \RuntimeException('Recipient has been suppressed')
        );

        $this->assertSame('recipient_suppressed', $suppressed['category']);
        $this->assertFalse($suppressed['retryable']);

        foreach (self::VENDORS as $vendor) {
            $this->assertStringNotContainsString($vendor, strtolower($suppressed['category']));
        }
    }
}
