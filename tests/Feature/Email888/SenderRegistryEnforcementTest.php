<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\OutboundPolicy;
use Tests\TestCase;

/**
 * EM-3/EM-4 - the sender and stream registries are ENFORCED, not merely offered.
 *
 * Source-level, deliberately. A call site that quietly picks its own sender
 * still sends mail perfectly well; the only way to catch it is to look.
 */
class SenderRegistryEnforcementTest extends TestCase
{
    /** Path B is scheduled for EM-7 convergence and is exempt until then. */
    private const EXEMPT = [
        'app/Connectors/EmailConnector.php',
    ];

    /** @return array<string,string> relative path => source */
    private function callSites(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '.bak')) {
                continue;
            }

            $rel = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $rel = str_replace('\\', '/', $rel);

            if (str_starts_with($rel, 'app/Core/Email888/')) {
                continue;   // the implementation itself
            }

            $src = (string) file_get_contents($file->getPathname());

            // A real call site, not a docblock mentioning one.
            if (preg_match('/^\s*(?!\*|\/\/).*Mail::(send|raw|html)\s*\(/m', $src) === 1) {
                $out[$rel] = $src;
            }
        }

        return $out;
    }

    public function test_every_mail_call_site_declares_a_purpose(): void
    {
        $undeclared = [];

        foreach ($this->callSites() as $path => $src) {
            if (in_array($path, self::EXEMPT, true)) {
                continue;
            }
            if (! str_contains($src, 'HDR_PURPOSE') && ! str_contains($src, 'SendEmailCommand')) {
                $undeclared[] = $path;
            }
        }

        $this->assertSame([], $undeclared,
            "These call sites send mail without declaring a purpose, so they choose their own "
            . "sender and stream: " . implode(', ', $undeclared));
    }

    public function test_every_declared_purpose_is_registered(): void
    {
        foreach ($this->callSites() as $path => $src) {
            preg_match_all("/HDR_PURPOSE,\s*'([a-z_]+)'/", $src, $m);

            foreach ($m[1] ?? [] as $purpose) {
                $this->assertTrue(
                    OutboundPolicy::isKnownPurpose($purpose),
                    "{$path} declares '{$purpose}', which is not in the purpose registry."
                );
            }
        }
    }

    public function test_security_and_account_mail_is_never_broadcast(): void
    {
        // A recipient who unsubscribes from marketing must not thereby lose the
        // ability to reset their password or receive a booking confirmation.
        foreach (['password_reset', 'account_security', 'mailbox_onboarding', 'billing',
                  'notification', 'booking', 'intake'] as $purpose) {
            $this->assertSame(
                'transactional',
                OutboundPolicy::resolve($purpose)['stream_class'],
                "{$purpose} must never travel on the broadcast stream."
            );
        }
    }

    public function test_bulk_mail_is_never_transactional(): void
    {
        // The converse, and the reason it matters: bulk complaints on the
        // transactional stream degrade the reputation that delivers resets.
        foreach (['campaign', 'sequence', 'newsletter'] as $purpose) {
            $this->assertSame(
                'broadcast',
                OutboundPolicy::resolve($purpose)['stream_class'],
                "{$purpose} must not share a stream with account mail."
            );
        }
    }

    public function test_no_purpose_resolves_to_an_unverified_sender(): void
    {
        $verified = ['hello@levelupgrowth.io', 'support@levelupgrowth.io'];

        foreach (array_keys(config('email888.purposes')) as $purpose) {
            $address = OutboundPolicy::resolve($purpose)['sender']['address'];
            $this->assertContains($address, $verified,
                "{$purpose} sends from {$address}, which is not a verified sender.");
        }
    }

    public function test_intake_keeps_the_enquirers_reply_address(): void
    {
        // Regression: giving intake a registry reply-to would silently redirect
        // every reply away from the person who submitted the form.
        $this->assertNull(
            OutboundPolicy::resolve('intake')['reply_to'],
            'intake must not impose a reply-to; the call site sets the enquirer address.'
        );
    }
}
